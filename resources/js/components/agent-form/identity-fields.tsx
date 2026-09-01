import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { User } from '@/types';

const statusOptions: { value: User['status']; label: string }[] = [
    { value: 'active', label: 'Active' },
    { value: 'invited', label: 'Invited' },
    { value: 'disabled', label: 'Disabled' },
];

const statusDotClassName: Record<User['status'], string> = {
    active: 'bg-success',
    invited: 'bg-warning',
    disabled: 'bg-muted-foreground',
};

export function IdentityFields({
    user,
    isEditing,
    errors,
}: {
    user?: User | null;
    isEditing: boolean;
    errors: Partial<
        Record<
            | 'name'
            | 'email'
            | 'phone'
            | 'status'
            | 'password'
            | 'password_confirmation',
            string
        >
    >;
}) {
    return (
        <div className="grid gap-6 sm:grid-cols-2">
            <div className="grid gap-1.5 sm:col-span-2">
                <Label htmlFor="name" className="text-sm font-semibold">
                    Full Name <span className="text-destructive">*</span>
                </Label>
                <Input
                    id="name"
                    name="name"
                    defaultValue={user?.name}
                    required
                    placeholder="Jane Doe"
                    className="h-10"
                />
                <InputError message={errors.name} />
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="email" className="text-sm font-semibold">
                    Email Address <span className="text-destructive">*</span>
                </Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    defaultValue={user?.email}
                    required
                    placeholder="jane@example.com"
                    className="h-10"
                />
                <InputError message={errors.email} />
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="phone" className="text-sm font-semibold">
                    Phone Number
                </Label>
                <Input
                    id="phone"
                    name="phone"
                    defaultValue={user?.phone ?? ''}
                    placeholder="+212 6 00 00 00 00"
                    className="h-10"
                />
                <InputError message={errors.phone} />
            </div>

            <div className="grid gap-1.5 sm:col-span-2">
                <Label htmlFor="status" className="text-sm font-semibold">
                    Account Status
                </Label>
                <Select name="status" defaultValue={user?.status ?? 'active'}>
                    <SelectTrigger id="status" className="h-10 w-full">
                        <SelectValue placeholder="Select a status" />
                    </SelectTrigger>
                    <SelectContent>
                        {statusOptions.map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                                <div className="flex items-center gap-2">
                                    <span
                                        className={cn(
                                            'inline-block size-2 rounded-full',
                                            statusDotClassName[option.value],
                                        )}
                                    />
                                    <span>{option.label}</span>
                                </div>
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={errors.status} />
            </div>

            <div className="sm:col-span-2">
                <h3 className="border-b pb-2 text-sm font-semibold tracking-tight">
                    Password
                </h3>
            </div>

            <div className="grid gap-1.5">
                <Label htmlFor="password" className="text-sm font-semibold">
                    {isEditing ? 'New Password' : 'Password'}
                    {!isEditing && <span className="text-destructive"> *</span>}
                </Label>
                <Input
                    id="password"
                    type="password"
                    name="password"
                    required={!isEditing}
                    placeholder={
                        isEditing ? 'Leave blank to keep current' : '••••••••'
                    }
                    autoComplete="new-password"
                    className="h-10"
                />
                <InputError message={errors.password} />
            </div>

            <div className="grid gap-1.5">
                <Label
                    htmlFor="password_confirmation"
                    className="text-sm font-semibold"
                >
                    Confirm Password
                    {!isEditing && <span className="text-destructive"> *</span>}
                </Label>
                <Input
                    id="password_confirmation"
                    type="password"
                    name="password_confirmation"
                    required={!isEditing}
                    placeholder="••••••••"
                    autoComplete="new-password"
                    className="h-10"
                />
                <InputError message={errors.password_confirmation} />
            </div>
        </div>
    );
}
