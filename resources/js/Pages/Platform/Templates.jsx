import { useT } from '@/lib/ui';
import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Box, Stack, Typography } from '@mui/material';
import { Button, Card, Field, Input, Shell, useConsoleBase } from '@/lib/ui';

export default function Templates({ templates, terms }) {
    const t = useT();
    return (
        <Shell title={t("Industry templates")}>
            <Typography variant="body2" color="text.secondary" sx={{ mt: -1, mb: 3 }}>{t("Each template renames the same production flow for one industry. A workspace uses the template selected for it.")}</Typography>
            <Stack spacing={3}>
                {templates.length ? templates.map((template) => <TemplateEditor key={template.key} template={template} terms={terms} />) : <Card sx={{ p: 3 }}><Typography variant="body2" color="text.secondary">{t("No industry templates yet.")}</Typography></Card>}
            </Stack>
        </Shell>
    );
}

function TemplateEditor({ template, terms }) {
    const t = useT();
    const base = useConsoleBase();
    const [vocabulary, setVocabulary] = useState(template.vocabulary || { en: {}, sw: {} });
    const form = useForm({ name: template.name });

    function setLabel(lang, key, value) {
        setVocabulary((current) => ({
            ...current,
            [lang]: { ...current[lang], [key]: value },
        }));
    }

    function save(event) {
        event.preventDefault();
        form.transform((data) => ({ name: data.name, vocabulary }));
        form.put(`${base}/templates/${template.key}`, {
            onFinish: () => form.transform((data) => data),
        });
    }

    return (
        <Card component="form" onSubmit={save} sx={{ p: 3 }}>
            <Typography component="h2" variant="h6">{template.name}</Typography>
            <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5, mb: 3 }}>{template.key}</Typography>
            <Stack spacing={3}>
                <Field label={t("Name")} error={form.errors.name}><Input required value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /></Field>
                <Box>
                    <Typography variant="subtitle2" sx={{ mb: 1 }}>{t("Word map")}</Typography>
                    <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>{t("The screen stays the same. These labels replace the standard words for this industry.")}</Typography>
                    <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', md: '1.1fr 1fr 1fr 1fr 1fr' }, gap: 1.5, alignItems: 'end' }}>
                        <Typography variant="caption" color="text.secondary" sx={{ display: { xs: 'none', md: 'block' } }}>{t("Word")}</Typography>
                        <Typography variant="caption" color="text.secondary" sx={{ display: { xs: 'none', md: 'block' } }}>{t("English")}</Typography>
                        <Typography variant="caption" color="text.secondary" sx={{ display: { xs: 'none', md: 'block' } }}>{t("English plural")}</Typography>
                        <Typography variant="caption" color="text.secondary" sx={{ display: { xs: 'none', md: 'block' } }}>{t("Kiswahili")}</Typography>
                        <Typography variant="caption" color="text.secondary" sx={{ display: { xs: 'none', md: 'block' } }}>{t("Kiswahili plural")}</Typography>
                        {terms.map((term) => (
                            <TermRow key={term.singular} term={term} vocabulary={vocabulary} error={form.errors} onChange={setLabel} />
                        ))}
                    </Box>
                </Box>
                <Stack direction="row" sx={{ justifyContent: 'flex-end' }}><Button icon="save" type="submit" loading={form.processing}>{t("Save template")}</Button></Stack>
            </Stack>
        </Card>
    );
}

function TermRow({ term, vocabulary, error, onChange }) {
    const label = (lang, key) => vocabulary?.[lang]?.[key] || '';

    return (
        <>
            <Typography variant="body2" sx={{ py: 1 }}>{term.label}</Typography>
            <Input aria-label={`${term.label} English`} required value={label('en', term.singular)} onChange={(event) => onChange('en', term.singular, event.target.value)} error={error[`vocabulary.en.${term.singular}`]} />
            {term.plural
                ? <Input aria-label={`${term.label} English plural`} required value={label('en', term.plural)} onChange={(event) => onChange('en', term.plural, event.target.value)} error={error[`vocabulary.en.${term.plural}`]} />
                : <Box sx={{ display: { xs: 'none', md: 'block' } }} />}
            <Input aria-label={`${term.label} Kiswahili`} required value={label('sw', term.singular)} onChange={(event) => onChange('sw', term.singular, event.target.value)} error={error[`vocabulary.sw.${term.singular}`]} />
            {term.plural
                ? <Input aria-label={`${term.label} Kiswahili plural`} required value={label('sw', term.plural)} onChange={(event) => onChange('sw', term.plural, event.target.value)} error={error[`vocabulary.sw.${term.plural}`]} />
                : <Box sx={{ display: { xs: 'none', md: 'block' } }} />}
        </>
    );
}
