import { useForm } from '@inertiajs/react';
import { PencilLine } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';

/**
 * A store with no online shop. Brands that only sell through WhatsApp,
 * Instagram or the phone still need one, so their manual orders can be
 * told apart from another brand's. Nothing to connect: a name is enough.
 */
export function ManualStoreCard() {
    const { t } = useTranslation();
    const form = useForm({ name: '' });

    return (
        <Card className="gap-0 py-0">
            <form
                className="flex h-full flex-col gap-4 p-5"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/stores/manual', {
                        onSuccess: () => form.reset(),
                    });
                }}
            >
                <div className="flex items-start gap-3">
                    <div className="flex size-10 shrink-0 items-center justify-center rounded-lg border bg-muted/30">
                        <PencilLine className="size-5 text-muted-foreground" />
                    </div>
                    <div className="min-w-0">
                        <h3 className="leading-none font-medium">
                            {t('Manual store')}
                        </h3>
                        <p className="mt-1.5 text-sm text-muted-foreground">
                            {t(
                                'For a brand with no online shop: orders taken by WhatsApp, Instagram, phone or in person.',
                            )}
                        </p>
                    </div>
                </div>

                <div className="mt-auto grid gap-2">
                    <Label htmlFor="manual-store-name">{t('Store name')}</Label>
                    <div className="flex gap-2">
                        <Input
                            id="manual-store-name"
                            value={form.data.name}
                            onChange={(event) =>
                                form.setData('name', event.target.value)
                            }
                            placeholder="Faizat"
                            maxLength={255}
                            required
                        />
                        <Button type="submit" disabled={form.processing}>
                            {t('Create')}
                        </Button>
                    </div>
                    <InputError message={form.errors.name} />
                </div>
            </form>
        </Card>
    );
}
