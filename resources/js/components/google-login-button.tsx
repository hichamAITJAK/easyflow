import { Button } from '@/components/ui/button';
import { redirect as googleRedirect } from '@/routes/auth/google';

/**
 * "Continue with Google" — a plain anchor on purpose: the OAuth redirect
 * must be a full-page navigation, not an Inertia visit.
 */
export function GoogleLoginButton({ label }: { label: string }) {
    return (
        <Button variant="outline" type="button" className="w-full" asChild>
            <a href={googleRedirect().url}>
                <svg viewBox="0 0 24 24" aria-hidden className="size-4">
                    <path
                        fill="#4285F4"
                        d="M23.5 12.27c0-.79-.07-1.55-.2-2.28H12v4.51h6.45a5.52 5.52 0 0 1-2.39 3.62v3h3.86c2.26-2.09 3.58-5.17 3.58-8.85Z"
                    />
                    <path
                        fill="#34A853"
                        d="M12 24c3.24 0 5.96-1.08 7.94-2.91l-3.86-3c-1.08.72-2.45 1.15-4.08 1.15-3.13 0-5.78-2.12-6.73-4.96H1.28v3.09A12 12 0 0 0 12 24Z"
                    />
                    <path
                        fill="#FBBC05"
                        d="M5.27 14.28a7.2 7.2 0 0 1 0-4.56V6.63H1.28a12 12 0 0 0 0 10.74l3.99-3.09Z"
                    />
                    <path
                        fill="#EA4335"
                        d="M12 4.76c1.76 0 3.34.6 4.59 1.8l3.42-3.42A11.98 11.98 0 0 0 1.28 6.63l3.99 3.09C6.22 6.88 8.87 4.76 12 4.76Z"
                    />
                </svg>
                {label}
            </a>
        </Button>
    );
}
