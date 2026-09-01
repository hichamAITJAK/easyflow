import { Plus, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import ProductController from '@/actions/App/Http/Controllers/Products/ProductController';
import { ImageDropzone } from '@/components/products/image-dropzone';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Empty,
    EmptyDescription,
    EmptyHeader,
    EmptyTitle,
} from '@/components/ui/empty';
import { InputGroup, InputGroupInput } from '@/components/ui/input-group';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    blankVariantDraft,
    variantCombinations,
    variantSignature,
} from '@/lib/product-variants';
import type { OptionDraft, VariantDraft } from '@/lib/product-variants';
import { cn } from '@/lib/utils';

/**
 * A single option row (name + its values as removable chips). Keeps its own
 * "new value" input state so typing a value doesn't rerender the whole
 * editor on every keystroke.
 */
function OptionRow({
    option,
    onChange,
    onRemove,
}: {
    option: OptionDraft;
    onChange: (option: OptionDraft) => void;
    onRemove: () => void;
}) {
    const [newValue, setNewValue] = useState('');

    const addValue = () => {
        const value = newValue.trim();

        if (value === '' || option.values.includes(value)) {
            setNewValue('');

            return;
        }

        onChange({ ...option, values: [...option.values, value] });
        setNewValue('');
    };

    const removeValue = (value: string) => {
        onChange({
            ...option,
            values: option.values.filter((existing) => existing !== value),
        });
    };

    return (
        <div className="grid gap-3 rounded-md border p-4">
            <div className="flex items-center gap-2">
                <InputGroup className="max-w-sm">
                    <InputGroupInput
                        value={option.name}
                        onChange={(event) =>
                            onChange({ ...option, name: event.target.value })
                        }
                        placeholder="Option name (e.g. Size, Color, Material)"
                        className="font-medium"
                    />
                </InputGroup>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    onClick={onRemove}
                    title="Delete option"
                    className="-m-1.5 size-11 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                >
                    <Trash2 className="size-4" />
                </Button>
            </div>

            <div className="flex flex-wrap items-center gap-2 pt-1">
                {option.values.map((value) => (
                    <Badge
                        key={value}
                        variant="secondary"
                        className="flex items-center gap-1.5 px-2.5 py-1 text-xs font-medium"
                    >
                        <span>{value}</span>
                        <button
                            type="button"
                            onClick={() => removeValue(value)}
                            title={`Remove ${value}`}
                            className="rounded-full p-0.5 text-muted-foreground transition-colors hover:bg-foreground/10 hover:text-foreground"
                        >
                            <X className="size-3" />
                        </button>
                    </Badge>
                ))}
                <InputGroup className="w-48 border-dashed">
                    <InputGroupInput
                        value={newValue}
                        onChange={(event) => setNewValue(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter' || event.key === ',') {
                                event.preventDefault();
                                addValue();
                            }
                        }}
                        onBlur={addValue}
                        placeholder="Type value & press Enter…"
                        className="text-xs"
                    />
                </InputGroup>
            </div>
        </div>
    );
}

/**
 * Editor for a manual product's variants: define options and their values,
 * and the cartesian product of combinations is generated below, each with
 * its own sku/price/inventory/availability. Per-combination edits are keyed
 * by a stable signature so they survive the matrix regenerating when
 * options change.
 */
export function ProductVariantsEditor({
    options,
    variantDrafts,
    onOptionsChange,
    onVariantDraftsChange,
}: {
    options: OptionDraft[];
    variantDrafts: Record<string, VariantDraft>;
    onOptionsChange: (options: OptionDraft[]) => void;
    onVariantDraftsChange: (drafts: Record<string, VariantDraft>) => void;
}) {
    const combinations = variantCombinations(options);

    const updateOption = (index: number, option: OptionDraft) => {
        onOptionsChange(
            options.map((existing, i) => (i === index ? option : existing)),
        );
    };

    const removeOption = (index: number) => {
        onOptionsChange(options.filter((_, i) => i !== index));
    };

    const addOption = () => {
        onOptionsChange([...options, { name: '', values: [] }]);
    };

    const updateDraft = (signature: string, changes: Partial<VariantDraft>) => {
        const current = variantDrafts[signature] ?? blankVariantDraft();
        onVariantDraftsChange({
            ...variantDrafts,
            [signature]: { ...current, ...changes },
        });
    };

    return (
        <div className="grid gap-6">
            <div className="grid gap-3">
                <div className="flex items-center gap-2">
                    <span className="flex size-5 items-center justify-center rounded-full bg-muted text-[11px] font-semibold text-muted-foreground">
                        1
                    </span>
                    <Label className="text-sm font-semibold">
                        Define options
                    </Label>
                </div>

                {options.length === 0 && (
                    <Empty className="border border-dashed py-8">
                        <EmptyHeader>
                            <EmptyTitle>No options added yet</EmptyTitle>
                            <EmptyDescription>
                                An option is an attribute like Size or Color.
                                Every combination of the values you add becomes
                                its own row in the table below.
                            </EmptyDescription>
                        </EmptyHeader>
                    </Empty>
                )}

                <div className="grid gap-3">
                    {options.map((option, index) => (
                        <OptionRow
                            key={index}
                            option={option}
                            onChange={(next) => updateOption(index, next)}
                            onRemove={() => removeOption(index)}
                        />
                    ))}
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={addOption}
                        className="h-9 w-full gap-1.5 border-dashed text-xs text-muted-foreground hover:text-foreground"
                    >
                        <Plus className="size-3.5" />
                        Add option
                    </Button>
                </div>
            </div>

            {combinations.length > 0 && (
                <div className="grid gap-3 border-t pt-6">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex items-center gap-2">
                            <span className="flex size-5 items-center justify-center rounded-full bg-muted text-[11px] font-semibold text-muted-foreground">
                                2
                            </span>
                            <Label className="text-sm font-semibold">
                                Review combinations
                            </Label>
                            <Badge
                                variant="secondary"
                                className="px-2 py-0.5 font-mono text-xs"
                            >
                                {combinations.length}
                            </Badge>
                        </div>
                        <span className="text-xs text-muted-foreground">
                            Blank SKU, price, or stock inherits the base
                            product's value.
                        </span>
                    </div>

                    <div
                        className="max-h-[380px] overflow-auto rounded-md border bg-[linear-gradient(to_right,var(--card),transparent_24px),linear-gradient(to_left,var(--card),transparent_24px)] bg-[length:24px_100%] bg-[position:left,right] bg-no-repeat"
                        style={{ backgroundAttachment: 'local, local' }}
                    >
                        <Table>
                            <TableHeader className="sticky top-0 z-10 bg-muted/60 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase backdrop-blur-md [&_th]:h-auto">
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="p-3 pl-4 font-semibold">
                                        Combination
                                    </TableHead>
                                    <TableHead className="p-3 font-semibold">
                                        SKU
                                    </TableHead>
                                    <TableHead className="p-3 text-center font-semibold">
                                        Image
                                    </TableHead>
                                    <TableHead className="p-3 text-right font-semibold">
                                        Price
                                    </TableHead>
                                    <TableHead className="p-3 text-right font-semibold">
                                        Stock
                                    </TableHead>
                                    <TableHead className="p-3 pr-4 text-center font-semibold">
                                        Status
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {combinations.map((combination) => {
                                    const signature =
                                        variantSignature(combination);
                                    const draft =
                                        variantDrafts[signature] ??
                                        blankVariantDraft();

                                    return (
                                        <TableRow key={signature}>
                                            <TableCell className="p-3 pl-4 whitespace-nowrap">
                                                <div className="flex flex-wrap items-center gap-1.5">
                                                    {combination.map(
                                                        (pair, idx) => (
                                                            <Badge
                                                                key={idx}
                                                                variant="outline"
                                                                className="font-medium"
                                                            >
                                                                <span className="mr-1 font-normal text-muted-foreground">
                                                                    {pair.name}:
                                                                </span>
                                                                {pair.value}
                                                            </Badge>
                                                        ),
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell className="p-3">
                                                <InputGroup className="w-28">
                                                    <InputGroupInput
                                                        value={draft.sku}
                                                        onChange={(event) =>
                                                            updateDraft(
                                                                signature,
                                                                {
                                                                    sku: event
                                                                        .target
                                                                        .value,
                                                                },
                                                            )
                                                        }
                                                        placeholder="—"
                                                        className="font-mono text-xs"
                                                    />
                                                </InputGroup>
                                            </TableCell>
                                            <TableCell className="p-3">
                                                <div className="flex justify-center">
                                                    <ImageDropzone
                                                        value={draft.image}
                                                        onChange={(path) =>
                                                            updateDraft(
                                                                signature,
                                                                {
                                                                    image: path,
                                                                },
                                                            )
                                                        }
                                                        uploadUrl={ProductController.uploadVariantImage.url()}
                                                        size="sm"
                                                    />
                                                </div>
                                            </TableCell>
                                            <TableCell className="p-3 text-right">
                                                <InputGroup className="ml-auto w-28">
                                                    <InputGroupInput
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        value={draft.price}
                                                        onChange={(event) =>
                                                            updateDraft(
                                                                signature,
                                                                {
                                                                    price: event
                                                                        .target
                                                                        .value,
                                                                },
                                                            )
                                                        }
                                                        placeholder="Default"
                                                        className="text-right font-mono text-xs placeholder:font-sans placeholder:italic"
                                                    />
                                                </InputGroup>
                                            </TableCell>
                                            <TableCell className="p-3 text-right">
                                                <InputGroup className="ml-auto w-24">
                                                    <InputGroupInput
                                                        type="number"
                                                        min="0"
                                                        value={
                                                            draft.inventory_quantity
                                                        }
                                                        onChange={(event) =>
                                                            updateDraft(
                                                                signature,
                                                                {
                                                                    inventory_quantity:
                                                                        event
                                                                            .target
                                                                            .value,
                                                                },
                                                            )
                                                        }
                                                        placeholder="Default"
                                                        className="text-right font-mono text-xs placeholder:font-sans placeholder:italic"
                                                    />
                                                </InputGroup>
                                            </TableCell>
                                            <TableCell className="p-3 pr-4 text-center">
                                                <div className="flex items-center justify-center gap-2">
                                                    <Switch
                                                        id={`avail-${signature}`}
                                                        checked={
                                                            draft.is_available
                                                        }
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            updateDraft(
                                                                signature,
                                                                {
                                                                    is_available:
                                                                        checked,
                                                                },
                                                            )
                                                        }
                                                    />
                                                    <Label
                                                        htmlFor={`avail-${signature}`}
                                                        className={cn(
                                                            'cursor-pointer text-[11px] font-medium select-none',
                                                            draft.is_available
                                                                ? 'text-success'
                                                                : 'text-muted-foreground',
                                                        )}
                                                    >
                                                        {draft.is_available
                                                            ? 'Available'
                                                            : 'Unavailable'}
                                                    </Label>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </div>
                </div>
            )}
        </div>
    );
}
