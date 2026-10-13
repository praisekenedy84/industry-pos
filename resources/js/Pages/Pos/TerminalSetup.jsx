import { useT } from '@/lib/ui';
import { Input } from '@/lib/ui';
import { useForm } from '@inertiajs/react';
import { Button, Field, Shell, inputClass } from '@/lib/ui';

export default function TerminalSetup({ branches = [] }) {
    const t = useT();
    const form = useForm({
        name: 'Front counter',
        branch_id: branches[0]?.id || '',
        paper_width: '80',
        fullscreen: false,
    });
    const ios = typeof navigator !== 'undefined' && /iPhone|iPad/.test(navigator.userAgent);

    return (
        <Shell title={t("Set up a terminal")}>
            <ol className="mb-6 list-decimal space-y-2 pl-5 text-sm">
                <li>{ios ? 'Share, then Add to Home Screen.' : 'Install this workspace from the browser menu.'}</li>
                <li>{t("Register the terminal below. The PIN only works on this device.")}</li>
                <li>{t("Choose the paper width, then connect and test the printer from the sales screen.")}</li>
            </ol>
            <form className="grid max-w-xl gap-3" onSubmit={(event) => { event.preventDefault(); form.post('/terminal/setup'); }}>
                <Field label={t("Name")} error={form.errors.name}>
                    <Input className={inputClass} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />
                </Field>
                <Field label={t("Branch")} error={form.errors.branch_id}>
                    <Input select className={inputClass} value={form.data.branch_id} onChange={(event) => form.setData('branch_id', event.target.value)}>
                        {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
                    </Input>
                </Field>
                <Field error={form.errors.paper_width} label={t("Paper width")}>
                    <Input select className={inputClass} value={form.data.paper_width} onChange={(event) => form.setData('paper_width', event.target.value)}>
                        <option value="80">{t("80 mm")}</option>
                        <option value="57">{t("57 mm")}</option>
                    </Input>
                </Field>
                <label className="flex items-center gap-2 text-sm">
                    <Input type="checkbox" checked={form.data.fullscreen} onChange={(event) => form.setData('fullscreen', event.target.checked)} />
                    {t("Full screen for this POS kiosk")}
                </label>
                <Button icon="add" loading={form.processing} type="submit" disabled={form.processing}>{t("Register terminal")}</Button>
            </form>
        </Shell>
    );
}
