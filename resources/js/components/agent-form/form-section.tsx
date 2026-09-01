import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

export function FormSection({
    icon: Icon,
    title,
    description,
    badge,
    children,
}: {
    icon: LucideIcon;
    title: string;
    description: string;
    badge?: ReactNode;
    children: ReactNode;
}) {
    return (
        <Card>
            <CardHeader>
                <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                    <CardTitle className="flex items-center gap-2">
                        <Icon className="size-5 text-muted-foreground" />
                        {title}
                    </CardTitle>
                    {badge}
                </div>
                <CardDescription>{description}</CardDescription>
            </CardHeader>

            <CardContent className="space-y-6">{children}</CardContent>
        </Card>
    );
}

export function SectionBadge({ children }: { children: ReactNode }) {
    return (
        <Badge
            variant="outline"
            className="self-start px-2 py-0.5 text-xs font-medium sm:self-center"
        >
            {children}
        </Badge>
    );
}
