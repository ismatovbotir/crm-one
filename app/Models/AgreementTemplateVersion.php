<?php

namespace App\Models;

use App\Enums\TemplateStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Template text for one locale and version (Spec 06 7.8). Published versions are immutable.
 *
 * @property TemplateStatus $status
 * @property list<array{code: string, label: string, default: bool}>|null $clauses
 */
#[Fillable(['template_id', 'version', 'locale', 'body', 'variables', 'clauses', 'status', 'published_by', 'published_at', 'checksum'])]
class AgreementTemplateVersion extends Model
{
    protected static function booted(): void
    {
        static::updating(function (AgreementTemplateVersion $version): void {
            if ($version->getRawOriginal('status') === TemplateStatus::Published->value && $version->isDirty(['body', 'clauses', 'locale', 'version'])) {
                throw new LogicException('Published template versions are immutable.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TemplateStatus::class,
            'variables' => 'array',
            'clauses' => 'array',
            'published_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<AgreementTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(AgreementTemplate::class, 'template_id');
    }
}
