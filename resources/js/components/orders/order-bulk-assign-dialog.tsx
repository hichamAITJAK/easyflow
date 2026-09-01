import { router } from '@inertiajs/react';
import { Loader2, Users } from 'lucide-react';
import { useState } from 'react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type AgentOption = { id: number; name: string };

export function OrderBulkAssignDialog({
    open,
    onOpenChange,
    orderIds,
    agents,
    getInitials,
    onAssigned,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    orderIds: number[];
    agents: AgentOption[];
    getInitials: (name: string) => string;
    onAssigned: () => void;
}) {
    const [selectedAgent, setSelectedAgent] = useState<string>('');
    const [processing, setProcessing] = useState(false);

    const handleAssign = () => {
        if (!selectedAgent && selectedAgent !== '__unassigned__') {
            return;
        }

        setProcessing(true);
        const agentId = selectedAgent === '__unassigned__' ? null : Number(selectedAgent);

        router.patch(
            '/orders/bulk/assign',
            { ids: orderIds, assigned_agent_id: agentId },
            {
                preserveScroll: true,
                onSuccess: () => {
                    onOpenChange(false);
                    setSelectedAgent('');
                    onAssigned();
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogTitle className="flex items-center gap-2">
                    <Users className="size-5 text-primary" />
                    Assign {orderIds.length}{' '}
                    {orderIds.length === 1 ? 'order' : 'orders'} in bulk
                </DialogTitle>
                <DialogDescription>
                    Select a confirmation agent to assign the selected orders to. This will distribute the orders instantly.
                </DialogDescription>

                <div className="py-4">
                    <Select
                        value={selectedAgent}
                        onValueChange={setSelectedAgent}
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue placeholder="Select an agent to assign..." />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__unassigned__">
                                <span className="text-muted-foreground">Unassign orders</span>
                            </SelectItem>
                            {agents.map((agent) => (
                                <SelectItem key={agent.id} value={String(agent.id)}>
                                    <div className="flex items-center gap-2">
                                        <Avatar className="size-5">
                                            <AvatarFallback className="text-[10px]">
                                                {getInitials(agent.name)}
                                            </AvatarFallback>
                                        </Avatar>
                                        <span>{agent.name}</span>
                                    </div>
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <DialogFooter className="gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        disabled={!selectedAgent || processing}
                        onClick={handleAssign}
                    >
                        {processing && <Loader2 className="mr-2 size-4 animate-spin" />}
                        Assign {orderIds.length === 1 ? 'order' : 'orders'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
