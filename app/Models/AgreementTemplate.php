<?php

namespace App\Models;

use App\Enums\AgreementKind;
use App\Enums\TemplateStatus;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Agreement template (Spec 06 7.8); text lives in versions per locale.
 *
 * @property AgreementKind $kind
 */
#[Fillable(['code', 'name', 'kind', 'is_active', 'created_by'])]
class AgreementTemplate extends Model
{
    use LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => AgreementKind::class, 'is_active' => 'boolean'];
    }

    /**
     * @return HasMany<AgreementTemplateVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(AgreementTemplateVersion::class, 'template_id')->orderByDesc('version')->orderBy('locale');
    }

    /** Latest published version for a locale. */
    public function publishedVersion(string $locale): ?AgreementTemplateVersion
    {
        return $this->versions()->where('locale', $locale)->where('status', TemplateStatus::Published)->first();
    }
}
