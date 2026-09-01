import InputError from '@/components/input-error';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { SalaryPeriod } from '@/types';

const periodOptions: { value: SalaryPeriod; label: string }[] = [
    { value: 'weekly', label: 'Weekly' },
    { value: 'monthly', label: 'Monthly' },
];

export function SalaryFields({
    amount,
    onAmountChange,
    period,
    onPeriodChange,
    errors,
}: {
    amount: string;
    onAmountChange: (value: string) => void;
    period: SalaryPeriod;
    onPeriodChange: (value: SalaryPeriod) => void;
    errors: { salary_amount?: string; salary_period?: string };
}) {
    return (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div className="grid gap-1.5">
                <Label
                    htmlFor="salary_amount"
                    className="text-sm font-semibold"
                >
                    Salary Amount
                </Label>
                <InputGroup>
                    <InputGroupInput
                        id="salary_amount"
                        name="salary_amount"
                        type="number"
                        step="0.01"
                        min="0"
                        value={amount}
                        onChange={(event) => onAmountChange(event.target.value)}
                        placeholder="3000"
                    />
                    <InputGroupAddon align="inline-end">MAD</InputGroupAddon>
                </InputGroup>
                <InputError message={errors.salary_amount} />
            </div>

            <div className="grid gap-1.5">
                <Label
                    htmlFor="salary_period"
                    className="text-sm font-semibold"
                >
                    Pay Frequency
                </Label>
                <Select
                    value={period}
                    onValueChange={(v) => onPeriodChange(v as SalaryPeriod)}
                >
                    <SelectTrigger id="salary_period" className="h-10 w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {periodOptions.map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={errors.salary_period} />
            </div>
        </div>
    );
}
