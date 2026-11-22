# Moonstone Craft Ops: project concept and implementation

This document explains what the system does, why it is built the way it is, and where each part lives in the code. Read it top to bottom once; after that, use the section headings as a map.

Stack: Laravel 13, Inertia v3 + React 19, Tailwind 4, SQLite (MySQL works too), Pest tests, `barryvdh/laravel-dompdf` for PDF invoices. Currency is LKR, shown as `Rs. 1,250.00`.

---

## 1. The business in one paragraph

Moonstone Craft makes and sells five crafts: **DIY painting kits, pipe cleaner crafts, gypsum pots, candles and resin**. You buy raw materials (plaster, wax, resin, paint, bags), make components in batches (alphabet figures, pots, candles), pack them into kits, and sell the kits retail or wholesale, by cash, bank transfer or cash on delivery. The system answers four questions at any time:

1. **What does each product really cost?** Stage by stage, including transport, wastage and courier.
2. **What should I charge?** Suggested prices per profit level, plus a box to test your own price.
3. **Who owes me money?** Partial wholesale payments and COD not yet remitted.
4. **Did I make a profit this month?** Income minus cost of goods, wastage and expenses.

---

## 2. Your requirements and where each one is built

| # | Requirement | How it works | Screen | Code |
|---|---|---|---|---|
| 1 | Store purchases in detail: quantity, cost, transport, refreshment; total and separate parts | Each purchase has lines (material, qty, line cost) plus transport, refreshment and other cost. Transport and other are spread over the lines by value to give a **landed unit cost**. Refreshment is saved as an expense (or folded into stock cost if you tick the box). | Purchases → New purchase | `PurchaseService`, `purchases`, `purchase_items` |
| 2 | When selling, store product code, name, quantity, wholesale or retail | Each sale line copies the option **code and name** at the time of sale, with qty, unit price and a cost snapshot. Sale type is retail or wholesale; wholesale prefills the wholesale price. | New sale | `SaleService`, `sales`, `sale_items` |
| 3 | Cash, cash on delivery, bank; wholesale paid later or partly paid | Payments are rows on the sale. Status is calculated: paid, partial, unpaid, COD pending. Add later payments on the sale page. Receivables lists everything still owed, with days overdue. | Sale page, Receivables | `sale_payments`, `SaleService::refreshStatus` |
| 4 | Store customer name, phone, address; fetch again | Phone is the key. Typing a phone or name on the sale form searches saved customers and fills the details. A new phone creates a customer; the same phone never creates a duplicate. | New sale, Customers | `customers`, `CustomerController::search` |
| 5 | Wastage of a single product, and of the business in general | Three kinds: **material** (spilled plaster), **product** (broken figures, takes the materials that product uses out of stock) and **business** (theft, damaged parcel, no stock change). | Wastage | `WastageService`, `wastage_logs` |
| 6 | Cost of each making stage, built up into the full kit cost, with product name and code | Components (figure, painting set, packing) are their own table and menu page. Each has stages (Mixing, Casting, Drying...) with material lines per batch. The component page shows each stage's cost and a running total, then wastage %, then unit cost. Kit options are built from components and single materials, so changing a part updates the cost. | Components → component page; Kits → option | `components`, `component_stages`, `stage_lines`, `option_parts`, `Costing` |
| 7 | Suggest a price per profit level; type my own price; with and without courier; one selling price | The option page shows suggested prices at 20/30/40/50/60% margin, for cost without and with courier. The **My price** box shows profit, margin and markup for both, with a red warning under your minimum margin. Each option has **one retail price** (and an optional wholesale price). Courier is never added into the price. | Option page | `Costing::suggest`, `CostMath` |
| 8 | Five crafts, each with categories, designs and options | Categories are the five crafts (with code prefixes PNT, PIP, GYP, CND, RSN). Kits (the `products` table) are your sellable designs; each kit has options (1 figure 2 paints, 2 figures 6 paints...). | Categories, Kits | `categories`, `products`, `product_options` |
| 9 | 80mm invoice printing, normal PDF download, view and print, for the customer | Every sale has: Print receipt (80mm or 58mm thermal), View A4, Download PDF, Print A4, and a signed customer link to send by WhatsApp. | Sale page | `InvoiceController`, `resources/views/invoices` |

---

## 3. Key ideas

### 3.1 Never store a calculated cost

Product and option costs are **always calculated** from current material prices. Change the price of plaster once and every figure, kit and suggestion updates. The only stored costs are **snapshots that must never change**:

- `sale_items.unit_cost`, `sales.cost_total`, `sales.courier_cost`: what a sale cost when it happened.
- `purchase_items.landed_unit_cost`: what a purchase line really cost.
- `wastage_logs.cost`: what a loss was worth on that day.

### 3.2 One stock ledger

Every stock change (purchase, sale, wastage, manual adjustment, reversal) writes a row to `stock_movements` through `StockService::move`. `materials.stock` is the running total; the ledger is the audit trail. Open a material to see its ledger.

### 3.3 Landed cost and average cost

```
extra            = transport + other (+ refreshment if ticked)
allocated(line)  = extra × line_cost / items_total
landed_unit_cost = (line_cost + allocated) / qty
new unit_cost    = (stock × unit_cost + qty × landed) / (stock + qty)     (if stock ≤ 0: landed)
```

Worked example: plaster 5,000 g for Rs. 1,600 and polythene 100 pcs for Rs. 800, transport Rs. 300, refreshment Rs. 150.
Plaster gets Rs. 200 of transport → Rs. 0.3600/g. Polythene gets Rs. 100 → Rs. 9.00/pc. Grand total Rs. 2,850. Refreshment Rs. 150 becomes an expense. (This exact case is a test in `tests/Feature/PurchaseTest.php`.)

### 3.4 Cost of a product, stage by stage

```
stage_cost       = Σ (line qty × material unit_cost)
batch_cost       = Σ stage_cost
unit_cost        = batch_cost / yield × (1 + wastage% / 100)
option_cost      = Σ (part qty × part unit cost)        part = material or component
cost_with_courier = option_cost + courier packing unit cost + courier fee estimate
```

Materials have a **type**: `material` (stocked, deducted when used) or `overhead` (labour, electricity: a fixed cost with no stock). Overheads count in cost but are never deducted from stock.

### 3.5 Two kinds of wastage, never double counted

- **Planned** wastage is the `wastage_pct` on a component. It is inside every kit cost and inside stock deduction.
- **Unplanned** wastage is a log entry. It never changes kit costs; it only reduces profit on the dashboard.

### 3.6 Pricing

```
price(target margin) = round up to step( cost / (1 - margin) )
profit = price - cost      margin = profit / price      markup = profit / cost
```

The step (default Rs. 10) and minimum margin (default 20%) are in **Settings → Business**.

### 3.7 Payments and status

| Case | What happens at save | Status |
|---|---|---|
| Retail, cash or bank | One payment for the total (or the amount you type) | paid |
| Cash on delivery | No payment | cod_pending |
| Wholesale, paid later | No payment, due date set | unpaid |
| Wholesale, deposit | One payment for the deposit | partial |
| Later payment | Add a row on the sale page; status recalculates | paid / partial |

For COD, when the courier pays you, add a **COD remittance** payment with the amount received and the **COD fee** they kept. The fee counts toward settling the sale and is subtracted from profit as an expense.

### 3.8 Profit on the dashboard

```
income      = Σ sale total (courier charged to the customer is income)
cost        = Σ (cost_total + courier_cost)
wastage     = material + product + business wastage
expenses    = Σ expenses (includes purchase refreshment) + COD fees
net profit  = income - cost - wastage - expenses
```

Purchases are shown for information only. They are **not** subtracted, because the materials are already counted in cost of goods when sold. Subtracting both would count materials twice.

---

## 4. Data model

```
components 1─* component_stages 1─* stage_lines ──> materials        (cost estimates: figure, painting set, packing)

categories 1─* products 1─* product_options 1─* option_parts ──> components | materials
                              product_options ──> components            (courier packing)

suppliers 1─* purchases 1─* purchase_items ──> materials
materials 1─* stock_movements          (every stock change)

customers 1─* sales 1─* sale_items ──> product_options
sales 1─* sale_payments

wastage_logs ──> materials | components | none (business)
expenses                                  (refreshment links back to its purchase)
settings                                  (key / value)
```

One migration per table, in `database/migrations/2026_11_20_0000NN_create_<table>_table.php`. Amounts are `decimal(12,2)`; unit costs `decimal(14,4)`; quantities `decimal(14,3)`.

Deleting is safe by design: materials, products and options used in a purchase or sale are protected by `restrictOnDelete()`, and the screen shows "cannot be deleted" instead of breaking history. Deleting a purchase, sale or wastage entry reverses its stock.

---

## 5. Code map

| Layer | Files |
|---|---|
| Formulas (no database) | `app/Services/CostMath.php` |
| Costing | `app/Services/Costing.php`: batch, stage, unit, option and courier cost; usage; suggestions |
| Stock | `app/Services/StockService.php`: the only place that changes `materials.stock` |
| Purchases | `app/Services/PurchaseService.php` |
| Sales and payments | `app/Services/SaleService.php` |
| Wastage | `app/Services/WastageService.php` |
| Reports | `app/Services/ReportService.php` (dashboard), CSV export in `DashboardController` |
| Controllers | `app/Http/Controllers/*Controller.php`, form requests in `app/Http/Requests` |
| Routes | `routes/web.php` (all behind login), `routes/settings.php` |
| Screens | `resources/js/pages/<area>/*.tsx` |
| Shared UI | `resources/js/components/ops.tsx`: page header, cards, tables, `FormModal`, status badges, money formatting |
| Invoices | `resources/views/invoices/receipt.blade.php` (80/58mm), `a4.blade.php` (HTML and PDF) |
| Brand | `resources/css/app.css` (colour tokens), `public/brand/` (logo files) |
| Tests | `tests/Unit/CostMathTest.php`, `tests/Feature/{Costing,Purchase,Sale,Wastage,Invoice,OpsPages}Test.php` |

Every action that touches stock or money runs inside `DB::transaction()` and locks the material row with `lockForUpdate()`.

---

## 6. Screens and daily use

**First-time setup**

1. **Settings → Business**: shop name, address, phone, bank details, receipt footer, receipt width (80/58mm), what happens after saving a sale, minimum margin, price rounding.
2. **Materials**: add every material with unit and current cost, and overheads such as Labour (per hour) and Electricity (per kWh). Set reorder levels.
3. **Components**: for each part you make (for example *Animal figure*, *Paint pot (filled)*, *Kit packing*), add stages and their material lines per batch, the yield per batch and planned wastage %. Check the cost build-up matches your hand calculation.
4. **Courier packing**: make a component such as *Courier packing* with a stage holding the bag and tape, so courier cost is counted.
5. **Kits**: create a kit, click **Add option** (codes like `PNT-ANM-1F2P`), pick its components and materials with quantities, the courier packing and fee estimate, and set your price. The page shows kit cost, profit in rupees and profit % as you type.

**Every day**

- **Bought something?** Purchases → New purchase. Enter lines, transport, refreshment, other. Stock and costs update.
- **Sold something?** New sale. Type the phone to find the customer, pick options by code, choose retail/wholesale, payment and delivery. Save, then print the receipt or send the PDF link.
- **Got paid later?** Open the sale (or Receivables) → Add payment.
- **Something broke or was lost?** Wastage → Log wastage.
- **Paid rent, ads, internet?** Expenses → New expense.
- **How is the month?** Dashboard, with CSV exports for sales, purchases and receivables.

---

## 7. Invoices and printing

| Button | What it does |
|---|---|
| Print receipt (80mm) | Opens the thermal receipt in a small window and prints at once |
| View A4 | Opens the PDF in the browser |
| Download PDF | Saves `INV-2026-0001.pdf` |
| Print A4 | Opens the A4 page and prints |
| Copy customer link | Copies a signed link (valid 30 days) to send by WhatsApp or email; a changed link is refused |

Invoices read the **saved** sale, so an old invoice never changes when prices change. Costs and profit are never printed.

**Thermal printer setup**: install the POS-80 driver, set paper to 80mm × continuous with zero margins, and in Chrome's print dialog choose that printer, Margins: None, Scale: 100%, no headers and footers. For one-click printing, start a dedicated Chrome profile with `--kiosk-printing`.

---

## 8. Look and feel

The UI follows the same approach as TMS v2: quiet business software with warm stone neutrals, bordered cards, compact tables with uppercase headers, and create/edit forms in **modals** so you never leave the list. Colours come from the logo:

| Token | Colour | Use |
|---|---|---|
| `brand-teal` | #0E5468 | Primary: buttons, links, active menu, table header on invoices |
| `brand-coral` | #FF5A5F | Accent: "Craft", kit badges, low stock, losses, INVOICE title |
| `brand-moon` | #F9C74F | Highlight rule on invoices |
| `brand-amber` | #F5A524 | Stage numbers, small highlights |
| `brand-leaf` | #2E8B47 | Profit, stock in |

New sale and New purchase stay as full pages, because they have many line rows and live totals that need the space.

---

## 9. Running, testing, extending

```bash
composer install && npm install
php artisan migrate --seed      # five crafts + sample materials and a painting kit
composer run dev                # app at http://localhost:8000, login test@example.com / password
php artisan test --compact tests/Unit tests/Feature/CostingTest.php tests/Feature/PurchaseTest.php tests/Feature/SaleTest.php tests/Feature/WastageTest.php tests/Feature/InvoiceTest.php tests/Feature/OpsPagesTest.php
```

Possible next steps:

- **Production batches**: today materials are deducted when you sell. If you make 50 figures first and sell later, add a `production_batches` table that deducts materials when you make and tracks finished stock.
- **Edit a sale**: today you delete and re-enter. An edit would reverse the stored usage and record again.
- **Sinhala / Tamil names on PDF**: register Noto Sans Sinhala in `config/dompdf.php` if names show as boxes.
- **QR code** on receipts linking to the PDF (`simplesoftwareio/simple-qrcode`).
- **Daily database backup** and a production `.env` before going live.
