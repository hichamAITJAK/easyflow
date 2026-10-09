export type CreativeKind = 'single' | 'pack';
export type CreativeStatus = 'testing' | 'active' | 'inactive';
export type RequestStatus = 'sent' | 'returned' | 'edits' | 'validated';
export type ContentType = 'video' | 'static';

export type Person = { id: number; name: string; initials: string };

export type ContentItem = {
    type: ContentType;
    count: number;
    /** index = creative number; an empty entry means free style */
    directions: string[];
};

export type HistoryEntry = {
    id: number;
    rev: number;
    validated_at: string | null;
    editor: string | null;
    items: ContentItem[];
    amount: number;
    drive_url: string | null;
};

export type CreativeProduct = {
    id: number;
    name: string;
    kind: CreativeKind;
    links: string[];
    description: string | null;
    status: CreativeStatus;
    works_count: number;
    last_push_at: string | null;
    editors: Person[];
    history: HistoryEntry[];
};

export type QueueItem = {
    id: number;
    product: {
        id: number;
        name: string;
        kind: CreativeKind;
        links_count: number;
        status: CreativeStatus;
    };
    editor: string;
    editor_id: number | null;
    origin: 'admin' | 'editor';
    status: RequestStatus;
    rev: number;
    when: string;
    admin_note: string | null;
    drive_url: string | null;
    editor_note: string | null;
    direction_points: string[];
    items: ContentItem[];
};

export type CommissionRow = {
    id: number;
    product: string;
    label: string | null;
    editor: string;
    validated_at: string;
    amount: number;
    paid: boolean;
    invoice_number: string | null;
};

export type AdminCreativesProps = {
    products: CreativeProduct[];
    queue: QueueItem[];
    commissions: {
        rows: CommissionRow[];
        pending: number;
        paidThisMonth: number;
        countThisMonth: number;
    };
    editors: Person[];
};
