<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['quality_report_id', 'actor_id', 'action', 'note'])]
class QualityReportReview extends Model
{
    /** @return BelongsTo<QualityReport, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(QualityReport::class, 'quality_report_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
