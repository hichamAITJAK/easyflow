import { Store as StoreIcon } from 'lucide-react';
import {
    Command,
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { useTranslation } from '@/hooks/use-translation';
import type { StoreConnectionStatus } from '@/types/store';

export type StoreOption = {
    id: number;
    name: string;
    connection_status?: StoreConnectionStatus;
};

/**
 * Store picker for the "Load orders" / "Load products" actions.
 *
 * Selecting a row starts the load immediately — there's no confirm step,
 * because loading upserts on the platform's own id and is safe to re-run.
 * The copy therefore has to say what will happen *before* the row is
 * chosen, since there's no second chance to explain it.
 *
 * "Load" throughout, matching the trigger buttons and the tables' empty
 * states — the same action must not change verb between the button and the
 * dialog it opens.
 */
export function StoreSyncCommand({
    open,
    onOpenChange,
    stores,
    onSelect,
    /** The noun being loaded, lowercase plural — "orders" or "products". */
    noun,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    stores: StoreOption[];
    /** Called with the chosen store id, or null for every connected store. */
    onSelect: (storeId: number | null) => void;
    noun: string;
}) {
    const { t } = useTranslation();

    const choose = (storeId: number | null) => {
        onOpenChange(false);
        onSelect(storeId);
    };

    // Loading skips anything not connected, so the two groups are split on
    // exactly that line rather than listing every store as if it were
    // equally available. A store with no status is treated as connected —
    // the field is optional on this type, and hiding a real store because a
    // caller didn't pass it would be the worse failure.
    const connected = stores.filter(
        (store) => (store.connection_status ?? 'connected') === 'connected',
    );
    const unavailable = stores.filter(
        (store) => (store.connection_status ?? 'connected') !== 'connected',
    );

    return (
        <CommandDialog
            open={open}
            onOpenChange={onOpenChange}
            title={`Load ${noun}`}
            description={`Choose which store to load ${noun} from.`}
        >
            <Command>
                {/* This project's CommandDialog renders children straight
                    into DialogContent without wrapping them in <Command>
                    (unlike upstream shadcn), so the root has to be supplied
                    here — without it every cmdk child reads an undefined
                    store context and throws on `.subscribe`. */}

                {/* The dialog's own title is sr-only, so sighted users would
                    otherwise see a bare search box with no statement of what
                    picking a row does. This is that statement. */}
                <div className="border-b px-3 py-2.5">
                    <p className="text-sm font-medium">
                        {t('Load :noun from', { noun })}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {t(
                            'Starts as soon as you pick. Existing :noun are updated, not duplicated.',
                            { noun },
                        )}
                    </p>
                </div>

                <CommandInput placeholder={t('Search stores by name…')} />
                <CommandList>
                    <CommandEmpty>
                        {t('No store matches that name.')}
                    </CommandEmpty>

                    {connected.length > 0 && (
                        <CommandGroup heading={t('Every connected store')}>
                            {/* `value` carries the label, not the id — cmdk
                                filters on it, so an id here would make the
                                row unsearchable by the words shown. */}
                            <CommandItem
                                value="All stores"
                                onSelect={() => choose(null)}
                            >
                                <StoreIcon className="size-4" />
                                {t('All stores')}
                                <span className="ml-auto text-xs text-muted-foreground">
                                    {connected.length}{' '}
                                    {connected.length === 1
                                        ? 'store'
                                        : 'stores'}
                                </span>
                            </CommandItem>
                        </CommandGroup>
                    )}

                    {connected.length > 0 && (
                        <CommandGroup heading={t('Just one store')}>
                            {connected.map((store) => (
                                <CommandItem
                                    key={store.id}
                                    value={store.name}
                                    onSelect={() => choose(store.id)}
                                >
                                    <StoreIcon className="size-4" />
                                    <span className="truncate">
                                        {store.name}
                                    </span>
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    )}

                    {/* Shown but not selectable: an agent looking for a store
                        that isn't here needs to know it exists and why it
                        can't be used, not to wonder whether they mis-typed. */}
                    {unavailable.length > 0 && (
                        <CommandGroup
                            heading={t('Not connected — reconnect in Stores')}
                        >
                            {unavailable.map((store) => (
                                <CommandItem
                                    key={store.id}
                                    value={store.name}
                                    disabled
                                >
                                    <StoreIcon className="size-4" />
                                    <span className="truncate">
                                        {store.name}
                                    </span>
                                    <span className="ml-auto text-xs">
                                        {store.connection_status === 'failed'
                                            ? t('Connection failed')
                                            : t('Setup unfinished')}
                                    </span>
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    )}

                    {/* The genuinely-empty case: no connected store exists,
                        so there is nothing to load from at all. CommandEmpty
                        never fires here — it only covers a failed search. */}
                    {connected.length === 0 && (
                        <div className="px-3 py-6 text-center">
                            <p className="text-sm font-medium">
                                {t('No connected stores')}
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {t('Connect a store before loading :noun.', {
                                    noun,
                                })}
                            </p>
                        </div>
                    )}
                </CommandList>
            </Command>
        </CommandDialog>
    );
}
