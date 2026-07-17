<?php

namespace App\Http\Requests\Api\V1\Application;

use App\Http\Requests\Api\V1\Application\Concerns\ResolvesApplicationUser;
use App\Models\ApplicationInformationResponseAttachment;
use Illuminate\Foundation\Http\FormRequest;

class DownloadApplicationInformationResponseAttachmentRequest extends FormRequest
{
    use ResolvesApplicationUser;

    public function authorize(): bool
    {
        $attachment = $this->route('attachment');
        if (! $attachment instanceof ApplicationInformationResponseAttachment) {
            return false;
        } $request = $attachment->response()->with('informationRequest.jobApplication.jobPosting')->first()?->informationRequest;

        return $request !== null && ($this->authenticatedUser()?->can('downloadAttachment', $request) ?? false);
    }

    public function rules(): array
    {
        return [];
    }
}
