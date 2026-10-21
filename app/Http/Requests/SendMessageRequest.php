<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'content' => 'required_without:file|nullable|string|max:1000',
            'file' => 'nullable|file|max:5120|mimes:jpg,jpeg,png,webp,gif,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,zip',
            'recipient_id' => 'nullable|integer|exists:users,id',
        ];
    }

    public function messages(): array
    {
        return [
            'content.required' => 'Le contenu du message est obligatoire.',
            'content.string' => 'Le contenu doit être du texte.',
            'file.max' => 'Le fichier ne peut pas dépasser 5 Mo.',
            'file.mimes' => 'Type de fichier non autorisé (images, PDF, Office, txt, zip).',
            'content.max' => 'Le message ne peut pas dépasser 1000 caractères.',
            'recipient_id.integer' => 'L\'ID du destinataire doit être un entier.',
            'recipient_id.exists' => 'Le destinataire n\'existe pas.',
        ];
    }
}
