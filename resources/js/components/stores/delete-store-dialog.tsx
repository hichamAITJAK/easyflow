import { Form } from '@inertiajs/react';
import { useState } from 'react';
import StoreController from '@/actions/App/Http/Controllers/Stores/StoreController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import type { StoreSummary } from '@/types';

export function DeleteStoreDialog({
    open,
    onOpenChange,
    store,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    store: StoreSummary | null;
}) {
    /**
     * Keyed by store id rather than cleared in an effect: without the key,
     * reopening the dialog for a *different* store would arrive with the
     * previous store's name already typed, leaving the confirmation one
     * click away from passing against the wrong store.
     */
    const [entry, setEntry] = useState<{ id: number | null; value: string }>({
        id: null,
        value: '',
    });

    if (!store) {
        return null;
    }

    const confirmation = entry.id === store.id ? entry.value : '';
    const matches = confirmation.trim() === store.name;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogTitle>Delete {store.name}?</DialogTitle>
                <DialogDescription>
                    This removes the store&apos;s synced products and any
                    commission rules or agent scopes limited to it. Past orders
                    are kept, but they will no longer be linked to this store.
                    This cannot be undone.
                </DialogDescription>

                <Form
                    {...StoreController.destroy.form(store.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => onOpenChange(false)}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <Field>
                                <FieldLabel htmlFor="delete-store-confirmation">
                                    Type{' '}
                                    <span className="font-semibold text-foreground">
                                        {store.name}
                                    </span>{' '}
                                    to confirm
                                </FieldLabel>
                                <Input
                                    id="delete-store-confirmation"
                                    name="confirmation"
                                    value={confirmation}
                                    onChange={(event) =>
                                        setEntry({
                                            id: store.id,
                                            value: event.target.value,
                                        })
                                    }
                                    autoComplete="off"
                                    autoCorrect="off"
                                    spellCheck={false}
                                    aria-invalid={!!errors.confirmation}
                                />
                                {errors.confirmation && (
                                    <FieldError>
                                        {errors.confirmation}
                                    </FieldError>
                                )}
                            </Field>

                            <DialogFooter className="gap-2">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => onOpenChange(false)}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing || !matches}
                                >
                                    {processing
                                        ? 'Deleting…'
                                        : 'Delete store'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
