import { cn } from '@/lib/utils';

/**
 * The full "Easy Flow" wordmark. Two renders of the same mark: black
 * lettering for light surfaces, white for dark — the plum "o" is the same
 * in both, so the brand colour never flips with the theme.
 *
 * Size it with a height class; width follows the image's own ratio.
 */
export default function AppWordmark({ className }: { className?: string }) {
    return (
        <>
            <img
                src="/assets/images/logo_wordmark.png"
                alt="EasyFlow"
                decoding="async"
                className={cn('block w-auto dark:hidden', className)}
            />
            <img
                src="/assets/images/logo_wordmark_dark.png"
                alt=""
                aria-hidden
                decoding="async"
                className={cn('hidden w-auto dark:block', className)}
            />
        </>
    );
}
