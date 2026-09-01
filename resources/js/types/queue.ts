export type QueueMetrics = {
    pending: number;
    reserved: number;
    failed: number;
    failedLastDay: number;
    /** Age of the oldest job still waiting to be picked up, in seconds. */
    oldestPendingSeconds: number | null;
};

export type QueueDepth = {
    queue: string;
    total: number;
    pending: number;
    oldestPendingSeconds: number | null;
};

export type FailedJob = {
    uuid: string;
    jobName: string;
    jobClass: string | null;
    queue: string;
    connection: string;
    failedAt: string;
    exception: string | null;
    exceptionFull: string;
};

export type QueueFilters = {
    search?: string;
    queue?: string;
    per_page?: string;
    page?: string;
};
