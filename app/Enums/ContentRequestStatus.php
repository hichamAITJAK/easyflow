<?php

namespace App\Enums;

/**
 * Where a content request sits in the review queue. `sent` and `edits`
 * wait on the editor, `returned` waits on the admin, `validated` is
 * terminal (and the only state with a pay row in the ledger).
 */
enum ContentRequestStatus: string
{
    case SENT = 'sent';
    case RETURNED = 'returned';
    case EDITS = 'edits';
    case VALIDATED = 'validated';
}
