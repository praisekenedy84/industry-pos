import { useEffect, useMemo, useState } from 'react';
import { CssBaseline, GlobalStyles } from '@mui/material';
import { createTheme, StyledEngineProvider, ThemeProvider } from '@mui/material/styles';

export default function AppTheme({ children }) {
    const [dark, setDark] = useState(() => document.documentElement.classList.contains('dark'));

    useEffect(() => {
        const observer = new MutationObserver(() => setDark(document.documentElement.classList.contains('dark')));
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        return () => observer.disconnect();
    }, []);

    const theme = useMemo(() => createTheme({
        palette: {
            mode: dark ? 'dark' : 'light',
            primary: { main: dark ? '#b6adff' : '#5745de', contrastText: dark ? '#21194a' : '#ffffff' },
            secondary: { main: dark ? '#ffabc0' : '#c83459' },
            background: { default: dark ? '#151427' : '#f7f6fe', paper: dark ? '#211f36' : '#ffffff' },
            text: { primary: dark ? '#f1f6f8' : '#182632', secondary: dark ? '#b3c1cc' : '#627382' },
            divider: dark ? '#49445f' : '#e6e2f3',
            success: { main: dark ? '#80d5a5' : '#27784c' },
            warning: { main: dark ? '#f5c576' : '#936000' },
            error: { main: dark ? '#ffb4ab' : '#c63636' },
        },
        shape: { borderRadius: 10 },
        typography: {
            fontFamily: "'Poppins', ui-sans-serif, system-ui, sans-serif",
            fontSize: 14,
            h4: { fontSize: '1.875rem', fontWeight: 700, letterSpacing: '-0.035em' },
            h5: { fontSize: '1.5rem', fontWeight: 650, letterSpacing: '-0.025em' },
            h6: { fontSize: '1.0625rem', fontWeight: 650 },
            button: { textTransform: 'none', fontWeight: 600 },
        },
        components: {
            MuiButton: {
                defaultProps: { disableElevation: true },
                styleOverrides: {
                    root: {
                        minHeight: 44, padding: '10px 16px', gap: 8, lineHeight: 1.4,
                        fontSize: 14, maxWidth: '100%', flexShrink: 0,
                        alignSelf: 'center', justifySelf: 'start',
                        borderRadius: 10,
                        '& .MuiButton-startIcon, & .MuiButton-endIcon': { margin: 0 },
                        '& .MuiButton-startIcon > svg, & .MuiButton-endIcon > svg': { width: 18, height: 18 },
                        '& svg': { flexShrink: 0 },
                        '&.Mui-focusVisible': { outline: `3px solid ${dark ? '#b6adff' : '#5745de'}`, outlineOffset: 3 },
                    },
                    sizeSmall: { minHeight: 44, padding: '8px 12px', fontSize: 13, gap: 6, '& .MuiButton-startIcon > svg, & .MuiButton-endIcon > svg': { width: 16, height: 16 } },
                    sizeLarge: { minHeight: 48, padding: '12px 20px', fontSize: 15, '& .MuiButton-startIcon > svg, & .MuiButton-endIcon > svg': { width: 20, height: 20 } },
                    outlined: { borderWidth: 1.5, '&:hover': { borderWidth: 1.5, backgroundColor: dark ? '#302b4e' : '#f0edff' } },
                    text: { '&:hover': { backgroundColor: dark ? '#302b4e' : '#f0edff' } },
                },
            },
            MuiIconButton: { styleOverrides: { root: { minWidth: 44, minHeight: 44, padding: 10 } } },
            MuiTextField: { defaultProps: { size: 'small', variant: 'outlined' } },
            MuiOutlinedInput: {
                styleOverrides: {
                    root: {
                        minHeight: 44,
                        '&.Mui-focused .MuiOutlinedInput-notchedOutline': { borderWidth: 1 },
                    },
                    input: { fontSize: 14 },
                },
            },
            MuiPaper: { defaultProps: { elevation: 0 }, styleOverrides: { root: { backgroundImage: 'none' } } },
            MuiTableCell: {
                styleOverrides: {
                    root: { borderColor: dark ? '#49445f' : '#e6e2f3', padding: '14px 20px', fontSize: 14 },
                    head: { backgroundColor: dark ? '#29263f' : '#f5f3ff', color: dark ? '#b3c1cc' : '#627382', fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.065em' },
                },
            },
            MuiFormHelperText: { styleOverrides: { root: { marginLeft: 0 } } },
            MuiTooltip: { defaultProps: { arrow: true } },
            MuiDialog: { defaultProps: { fullWidth: true, maxWidth: 'sm' } },
            MuiDialogActions: { styleOverrides: { root: { flexWrap: 'wrap', gap: 8, '& > :not(style) ~ :not(style)': { marginLeft: 0 } } } },
            MuiAlert: { styleOverrides: { root: { borderRadius: 10 } } },
        },
    }), [dark]);

    return (
        <StyledEngineProvider enableCssLayer>
            <GlobalStyles styles="@layer theme, base, mui, components, utilities;" />
            <ThemeProvider theme={theme}>
                <CssBaseline />
                {children}
            </ThemeProvider>
        </StyledEngineProvider>
    );
}
