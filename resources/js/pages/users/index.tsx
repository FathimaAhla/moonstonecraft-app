import { Head, useForm, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { DeleteButton, EditButton, EmptyRow, Field, FormModal, NativeSelect, Page, PageHeader, Panel, Table } from '@/components/ops';
import * as users from '@/routes/users';

type Row = { id: number; name: string; email: string; role: string };

const empty = { name: '', email: '', role: 'staff', password: '' };

export default function Users({ users: rows, roles }: { users: Row[]; roles: string[] }) {
    const { auth } = usePage().props;
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Row | null>(null);
    const form = useForm(empty);

    const openModal = (user: Row | null) => {
        setEditing(user);
        form.clearErrors();
        form.setData(user ? { name: user.name, email: user.email, role: user.role, password: '' } : empty);
        setOpen(true);
    };

    const submit = () => {
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };

        if (editing) {
            form.put(users.update.url(editing.id), options);
        } else {
            form.post(users.store.url(), options);
        }
    };

    return (
        <>
            <Head title="Users" />
            <Page>
                <PageHeader title="Users" description="Admins can manage everything including users. Staff use the day-to-day screens.">
                    <Button onClick={() => openModal(null)}><Plus /> New user</Button>
                </PageHeader>
                <Panel flush>
                    <Table>
                        <thead><tr><th>Name</th><th>Email</th><th>Role</th><th /></tr></thead>
                        <tbody>
                            {rows.length === 0 && <EmptyRow colSpan={4}>No users yet.</EmptyRow>}
                            {rows.map((u) => (
                                <tr key={u.id}>
                                    <td className="font-medium">{u.name}</td>
                                    <td>{u.email}</td>
                                    <td className="capitalize">{u.role}</td>
                                    <td className="space-x-3 text-right whitespace-nowrap">
                                        <EditButton onClick={() => openModal(u)} />
                                        {u.id !== auth.user.id && <DeleteButton url={users.destroy.url(u.id)} message={`Delete ${u.name}?`} />}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </Table>
                </Panel>
            </Page>

            <FormModal open={open} onOpenChange={setOpen} title={editing ? `Edit ${editing.name}` : 'New user'} onSubmit={submit} processing={form.processing}>
                <Field label="Name" error={form.errors.name}><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus /></Field>
                <Field label="Email" error={form.errors.email}><Input type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} /></Field>
                <Field label="Role" error={form.errors.role}>
                    <NativeSelect value={form.data.role} onChange={(e) => form.setData('role', e.target.value)}>
                        {roles.map((role) => <option key={role} value={role} className="capitalize">{role === 'admin' ? 'Admin' : 'Staff'}</option>)}
                    </NativeSelect>
                </Field>
                <Field label={editing ? 'New password (leave blank to keep)' : 'Password'} error={form.errors.password}>
                    <Input type="password" autoComplete="new-password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                </Field>
            </FormModal>
        </>
    );
}

Users.layout = { breadcrumbs: [{ title: 'Users', href: users.index() }] };
