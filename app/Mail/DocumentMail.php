<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

/** A receipt, course form, slip or notice. The email is the document itself, and a copy is attached for saving or printing. */
class DocumentMail extends Mailable
{
    public function __construct(public string $subjectLine, public string $body, public ?string $fileName = null)
    {
    }

    public function build(): static
    {
        $this->subject($this->subjectLine)->html($this->body);
        if ($this->fileName) {
            $this->attachData($this->body, $this->fileName, ['mime' => 'text/html']);
        }

        return $this;
    }
}
