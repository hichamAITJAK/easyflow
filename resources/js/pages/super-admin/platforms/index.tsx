import { Head, Link } from '@inertiajs/react';
import { Pencil, Store } from 'lucide-react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useTranslation } from '@/hooks/use-translation';
import SuperAdminLayout from '@/layouts/super-admin/layout';
import { edit as editPlatform } from '@/routes/super-admin/platforms';
import type { CatalogPlatform } from '@/types';

export default function SuperAdminPlatformsIndex({
    platforms,
}: {
    platforms: CatalogPlatform[];
}) {
    const { t } = useTranslation();

    return (
        <SuperAdminLayout>
            <Head title={t('Platforms')} />

            <div className="space-y-6">
                <Heading
                    title={t('E-commerce platforms')}
                    description={t(
                        'The platforms tenants can connect a store from. Each one is backed by its own integration code, so the catalog itself is fixed — you can edit how a platform is presented.',
                    )}
                />

                <div className="grid gap-4 md:grid-cols-2">
                    {platforms.map((platform) => (
                        <Card key={platform.id}>
                            <CardContent className="flex items-start gap-4">
                                {platform.logo_url ? (
                                    <img
                                        src={platform.logo_url}
                                        alt=""
                                        className="size-10 shrink-0 rounded-md object-contain"
                                    />
                                ) : (
                                    <div className="flex size-10 shrink-0 items-center justify-center rounded-md border text-muted-foreground">
                                        <Store className="size-4" />
                                    </div>
                                )}

                                <div className="min-w-0 flex-1 space-y-1">
                                    <div className="flex items-center gap-2">
                                        <span className="font-medium">
                                            {platform.name}
                                        </span>
                                        <span className="font-mono text-xs text-muted-foreground">
                                            {platform.slug}
                                        </span>
                                    </div>
                                    <p className="text-sm text-balance text-muted-foreground">
                                        {platform.description ??
                                            'No description.'}
                                    </p>
                                    <p className="text-xs text-muted-foreground tabular-nums">
                                        {platform.stores_count === 1
                                            ? t(':count connected store', {
                                                  count: platform.stores_count,
                                              })
                                            : t(':count connected stores', {
                                                  count: platform.stores_count,
                                              })}
                                    </p>
                                </div>

                                <Button variant="outline" size="sm" asChild>
                                    <Link href={editPlatform(platform.id)}>
                                        <Pencil />
                                        {t('Edit')}
                                    </Link>
                                </Button>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </div>
        </SuperAdminLayout>
    );
}
