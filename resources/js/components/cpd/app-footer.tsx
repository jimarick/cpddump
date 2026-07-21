import { Link } from '@inertiajs/react';
import { Sparkle } from '@/components/brand/sparkle';
import { Wordmark } from '@/components/brand/wordmark';

/**
 * The quiet signed-in footer: the wordmark, the promise, the trust links.
 * The sparkle on the AI link is the same mark that flags every AI feature
 * in the product.
 */
export function AppFooter() {
    return (
        <footer className="mt-10 border-t border-dashed border-ink/18">
            <div className="mx-auto flex max-w-[1080px] flex-col items-center justify-between gap-3 px-4 py-6 text-[12.5px] text-stone-500 md:flex-row md:px-6">
                <div className="flex items-center gap-2.5">
                    <Wordmark size="sm" />
                    <span>· your words, with some AI sprinkles</span>
                </div>
                <div className="flex flex-wrap items-center justify-center gap-x-[22px] gap-y-2">
                    <Link
                        href="/ai"
                        className="flex items-center gap-1.5 font-semibold text-brand-dark hover:text-ink"
                    >
                        <Sparkle size={10} className="text-brand" />
                        How we use AI
                    </Link>
                    <Link href="/privacy" className="hover:text-ink">
                        Privacy policy
                    </Link>
                    <Link href="/terms" className="hover:text-ink">
                        Terms
                    </Link>
                    <a href="mailto:hello@cpddump.com" className="hover:text-ink">
                        Support
                    </a>
                </div>
            </div>
        </footer>
    );
}
