<?php

namespace App\Actions\Agreements;

use App\Enums\AgreementKind;
use App\Enums\TemplateStatus;
use App\Models\AgreementTemplate;
use App\Models\AgreementTemplateVersion;
use App\Models\User;
use App\Services\Agreements\Exceptions\AgreementActionException;
use App\Services\Agreements\TemplateRenderer;
use App\Services\Agreements\TemplateSanitizer;
use Illuminate\Support\Facades\DB;

/**
 * Agreement templates (Spec 06 7.8, DEC-10): create a template, keep one draft version per
 * language, publish it after the lint passes (body sanitized, checksum stored, the previous
 * published version of that language retired). Published versions are immutable.
 */
class ManageAgreementTemplates
{
    public function __construct(protected TemplateRenderer $renderer, protected TemplateSanitizer $sanitizer) {}

    public function create(string $code, string $name, AgreementKind $kind, User $user): AgreementTemplate
    {
        return AgreementTemplate::query()->create(['code' => $code, 'name' => $name, 'kind' => $kind, 'is_active' => true, 'created_by' => $user->id]);
    }

    /**
     * Creates or updates the draft version of a language.
     *
     * @param  list<array{code: string, label: string, default: bool}>  $clauses
     */
    public function saveDraft(AgreementTemplate $template, string $locale, string $body, array $clauses): AgreementTemplateVersion
    {
        $draft = $template->versions()->where('locale', $locale)->where('status', TemplateStatus::Draft)->first();

        if ($draft === null) {
            $next = (int) $template->versions()->where('locale', $locale)->max('version') + 1;
            $draft = new AgreementTemplateVersion(['template_id' => $template->id, 'version' => $next, 'locale' => $locale, 'status' => TemplateStatus::Draft]);
        }

        $draft->fill(['body' => $body, 'clauses' => array_values($clauses), 'variables' => $this->renderer->placeholders($body)])->save();

        return $draft;
    }

    public function publish(AgreementTemplateVersion $version, User $user): AgreementTemplateVersion
    {
        if ($version->status !== TemplateStatus::Draft) {
            throw new AgreementActionException('template_not_draft');
        }

        $errors = $this->renderer->lint($version->body, array_column($version->clauses ?? [], 'code'));

        if ($errors !== []) {
            throw new AgreementActionException('template_invalid', ['problems' => implode('; ', array_map(fn (array $error): string => __('agreements.validation.template_'.$error['key'], $error['params']), $errors))]);
        }

        return DB::transaction(function () use ($version, $user): AgreementTemplateVersion {
            AgreementTemplateVersion::query()
                ->where('template_id', $version->template_id)
                ->where('locale', $version->locale)
                ->where('status', TemplateStatus::Published)
                ->update(['status' => TemplateStatus::Retired, 'updated_at' => now()]);

            $body = $this->sanitizer->sanitize($version->body);
            $version->forceFill([
                'body' => $body,
                'status' => TemplateStatus::Published,
                'published_by' => $user->id,
                'published_at' => now(),
                'checksum' => hash('sha256', $body),
                'variables' => $this->renderer->placeholders($body),
            ])->save();

            return $version;
        });
    }

    public function discardDraft(AgreementTemplateVersion $version): void
    {
        if ($version->status !== TemplateStatus::Draft) {
            throw new AgreementActionException('template_not_draft');
        }

        $version->delete();
    }
}
