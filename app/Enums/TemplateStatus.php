<?php

namespace App\Enums;

/**
 * Agreement template version state (Spec 06 section 7.8): published versions are immutable.
 */
enum TemplateStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Retired = 'retired';

    public function label(): string
    {
        return __('agreements.template_status.'.$this->value);
    }
}
