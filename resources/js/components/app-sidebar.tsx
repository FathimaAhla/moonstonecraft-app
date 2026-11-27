import { Link, usePage } from '@inertiajs/react';
import { Boxes, Calculator, ClipboardCheck, HandCoins, LayoutGrid, Package, PackageCheck, Palette, Puzzle, Receipt, Settings, Shapes, ShoppingBag, ShoppingCart, Tags, Trash2, Truck, UserCog, Users, Wallet } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard, priceCheck } from '@/routes';
import * as business from '@/routes/business';
import * as categories from '@/routes/categories';
import * as components from '@/routes/components';
import * as customers from '@/routes/customers';
import * as expenses from '@/routes/expenses';
import * as materials from '@/routes/materials';
import * as options from '@/routes/options';
import * as figures from '@/routes/figures';
import * as paintSets from '@/routes/paint-sets';
import * as products from '@/routes/products';
import * as purchases from '@/routes/purchases';
import * as receivables from '@/routes/receivables';
import * as sales from '@/routes/sales';
import * as usersRoutes from '@/routes/users';
import * as stock from '@/routes/stock';
import * as stockChecks from '@/routes/stock-checks';
import * as wastage from '@/routes/wastage';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
    { title: 'New sale', href: sales.create(), icon: ShoppingCart },
    { title: 'Price check', href: priceCheck(), icon: Calculator },
    { title: 'Sales', href: sales.index(), icon: Receipt },
    { title: 'Receivables', href: receivables.index(), icon: HandCoins },
    { title: 'Customers', href: customers.index(), icon: Users },
    { title: 'Components', href: components.index(), icon: Puzzle },
    { title: 'Kits', href: products.index(), icon: Package },
    { title: 'Products', href: options.index(), icon: ShoppingBag },
    { title: 'Figures', href: figures.index(), icon: Shapes },
    { title: 'Paint sets', href: paintSets.index(), icon: Palette },
    { title: 'Categories', href: categories.index(), icon: Tags },
    { title: 'Stock', href: stock.index(), icon: PackageCheck },
    { title: 'Stock checks', href: stockChecks.index(), icon: ClipboardCheck },
    { title: 'Materials', href: materials.index(), icon: Boxes },
    { title: 'Purchases', href: purchases.index(), icon: Truck },
    { title: 'Wastage', href: wastage.index(), icon: Trash2 },
    { title: 'Expenses', href: expenses.index(), icon: Wallet },
];

const footerNavItems: NavItem[] = [{ title: 'Business settings', href: business.edit(), icon: Settings }];

export function AppSidebar() {
    const { auth } = usePage().props;
    const items = auth.user?.role === 'admin' ? [...mainNavItems, { title: 'Users', href: usersRoutes.index(), icon: UserCog }] : mainNavItems;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={items} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
