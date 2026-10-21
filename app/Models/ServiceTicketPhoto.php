<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Photo attached to a repair report (Spec 09 §11): re-encoded JPEG on the private disk, served by
 * an authorizing controller.
 */
#[Fillable(['service_ticket_id', 'path', 'uploaded_by', 'created_at'])]
class ServiceTicketPhoto extends Model
{
    public const UPDATED_AT = null;
}
