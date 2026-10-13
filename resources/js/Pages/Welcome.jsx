import { useT } from '@/lib/ui';
import { Link } from '@inertiajs/react';
import { Button } from '@/lib/ui';
import { Layers, Factory, Package, ShoppingBag, Check } from 'lucide-react';

export default function Welcome() {
    const t = useT();
    return (
        <div className="min-h-screen bg-surface">
            <header className="mx-auto flex max-w-7xl items-center justify-between gap-4 px-6 py-6 lg:px-10">
                <Link href="/" className="flex items-center gap-2.5 text-xl font-bold tracking-tight"><span className="rounded-xl bg-primary p-2.5 text-white"><Layers size={22} /></span>Makerly</Link>
                <Button icon="login" component={Link} href="/login" tone="soft">{t("Sign in")}</Button>
            </header>
            <main>
                <section className="mx-auto grid max-w-7xl items-center gap-12 px-6 py-14 lg:grid-cols-[1.1fr_1fr] lg:gap-16 lg:px-10 lg:py-20">
                    <div>
                        <p className="mb-6 inline-flex items-center gap-2 rounded-full border border-hairline bg-canvas px-3 py-1.5 text-xs font-medium text-link"><span className="size-1.5 rounded-full bg-primary" />{t("For businesses that make and sell")}</p>
                        <h1 className="text-5xl font-semibold leading-[1.08] tracking-[-0.045em] sm:text-6xl lg:text-7xl">{t("From first ingredient to")} <span className="text-link">{t("final sale.")}</span></h1>
                        <p className="mt-6 max-w-lg text-lg leading-relaxed text-muted">{t("Less juggling. More making. Bring production, inventory and point of sale into one workspace that works as hard as you do.")}</p>
                        <Button icon="add" component={Link} href="/signup" size="large" sx={{ mt: 4 }}>{t("Start your workspace")}</Button>
                        <p className="mt-6 flex flex-wrap gap-5 text-xs text-muted"><span><Check size={14} className="mr-1 inline text-link" />{t("English & Kiswahili")}</span><span><Check size={14} className="mr-1 inline text-link" />{t("Prices in TZS")}</span></p>
                    </div>
                    <div className="rounded-[2rem] bg-canvas p-5 sm:p-9">
                        <div className="overflow-hidden rounded-2xl border border-hairline bg-surface shadow-xl shadow-black/5">
                            <div className="flex items-center gap-2 border-b border-hairline px-6 py-4 text-sm font-semibold"><Layers size={17} className="text-link" />{t("Your connected workspace")}</div>
                            <div className="p-6 sm:p-8">
                                <p className="text-xs text-muted">{t("One place to bring it all together")}</p>
                                <h2 className="mt-1 text-2xl font-semibold tracking-tight">{t("Good things take shape.")}</h2>
                                <div className="mt-7 space-y-3">
                                    {[[Factory, '01', 'Plan your production'], [Package, '02', 'Keep stock in sight'], [ShoppingBag, '03', 'Make every sale count']].map(([Icon, number, label]) => <div key={number} className="flex items-center gap-3 rounded-xl border border-hairline p-4"><span className="rounded-xl bg-sticker-blue p-2.5 text-link"><Icon size={20} strokeWidth={1.6} /></span><div><p className="text-[10px] uppercase tracking-widest text-muted">{t("Step")} {number}</p><p className="mt-0.5 text-sm font-semibold">{label}</p></div><Check size={17} className="ml-auto text-link" /></div>)}
                                </div>
                                <p className="mt-6 border-t border-hairline pt-4 text-xs leading-relaxed text-muted">{t("Recipes → Production → Inventory → Point of sale")}</p>
                            </div>
                        </div>
                        <p className="mt-5 text-center text-[10px] uppercase tracking-[0.2em] text-muted">{t("A simpler way to run your everyday")}</p>
                    </div>
                </section>
                <section className="border-y border-hairline bg-canvas/60">
                    <div className="mx-auto grid max-w-7xl gap-8 px-6 py-12 md:grid-cols-3 lg:px-10">
                        {[[Factory, 'Make with confidence', 'Connect recipes, materials and production orders. Know what goes into every product.'], [Package, 'Stay one step ahead', 'Track inventory, manage suppliers and stay ahead of your next reorder.'], [ShoppingBag, 'Bring sales together', 'Keep sales, customers and receipts connected to the rest of your business.']].map(([Icon, title, description]) => <article key={title}><Icon size={23} className="mb-4 text-link" /><h2 className="text-lg font-semibold tracking-tight">{title}</h2><p className="mt-2 max-w-sm text-sm leading-relaxed text-muted">{description}</p></article>)}
                    </div>
                </section>
            </main>
            <footer className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-6 py-6 text-xs text-muted lg:px-10"><span>{t("Makerly · Production & point of sale")}</span><Link href="/platform/login" className="hover:text-link">{t("Platform access")}</Link></footer>
        </div>
    );
}
