import { Head, Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

type Props = {
    status: number;
    title: string;
    message: string;
};

export default function ErrorPage({ status, title, message }: Props) {
    return (
        <>
            <Head title={title} />

            <div className="flex min-h-svh flex-col items-center justify-center gap-8 bg-background p-6 text-center">
                <AppLogoIcon className="h-10 w-auto fill-current text-foreground" />

                <div className="space-y-2">
                    <p className="text-sm font-medium text-muted-foreground">
                        Error {status}
                    </p>
                    <h1 className="text-2xl font-semibold">{title}</h1>
                    <p className="text-sm text-balance text-muted-foreground">
                        {message}
                    </p>
                </div>

                <div className="flex items-center gap-3">
                    <Button variant="outline" onClick={() => history.back()}>
                        Go back
                    </Button>
                    <Button asChild>
                        <Link href={dashboard()}>Go to dashboard</Link>
                    </Button>
                </div>
            </div>
        </>
    );
}
