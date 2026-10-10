<?php

namespace App\Http\Controllers;

use App\Mail\DocumentMail;
use App\Models\DocumentEmail;
use App\Models\Payment;
use App\Models\RegistrationForm;
use App\Models\Setting;
use App\Services\DocumentMailer;
use App\Support\MailSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

/** The log of emailed documents, sending one again, which documents are emailed, and the mail server. */
class DocumentEmailController extends Controller
{
    public function __construct(private DocumentMailer $mailer)
    {
        $this->middleware('permission:View email log')->only('index');
        $this->middleware('permission:Resend emailed documents')->only('resend');
        $this->middleware('permission:Edit email settings')->only('settings', 'test');
    }

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));
        $emails = DocumentEmail::with('sender')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('recipient', 'like', "%{$q}%")->orWhere('subject', 'like', "%{$q}%")))
            ->when($request->filled('type'), fn ($w) => $w->where('type', $request->query('type')))
            ->when($request->filled('status'), fn ($w) => $w->where('status', $request->query('status')))
            ->latest('id')->paginate(30)->withQueryString();

        return view('emails.index', [
            'pagetitle' => 'Emailed documents', 'emails' => $emails,
            'failed' => DocumentEmail::where('status', 'failed')->where('created_at', '>=', now()->subDays(7))->count(),
            'enabled' => collect(DocumentMailer::TYPES)->mapWithKeys(fn ($t, $k) => [$k => DocumentMailer::enabled($k)]),
            'mail' => ['mailer' => MailSettings::get('mailer'), 'host' => MailSettings::get('host'), 'port' => MailSettings::get('port'), 'encryption' => MailSettings::get('encryption'),
                'username' => MailSettings::get('username'), 'has_password' => (bool) Setting::get('mail.password'), 'from_address' => MailSettings::get('from_address'), 'from_name' => MailSettings::get('from_name')],
            'current' => config('mail.default'),
        ]);
    }

    public function resend(Request $request, DocumentEmail $email): RedirectResponse
    {
        try {
            $n = $this->mailer->resend($email, $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with($n ? 'success' : 'error', $n ? 'Sent again. Check the log for the result.' : 'Nothing was sent: this kind of email is switched off or the person has no email address.');
    }

    public function settings(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'documents' => ['nullable', 'array'],
            'mailer' => ['nullable', 'in:smtp,log'], 'host' => ['nullable', 'required_if:mailer,smtp', 'string', 'max:190'],
            'port' => ['nullable', 'integer', 'between:1,65535'], 'encryption' => ['nullable', 'in:tls,ssl'],
            'username' => ['nullable', 'string', 'max:190'], 'password' => ['nullable', 'string', 'max:190'],
            'from_address' => ['nullable', 'email', 'max:190'], 'from_name' => ['nullable', 'string', 'max:190'],
        ]);
        foreach (array_keys(DocumentMailer::TYPES) as $type) {
            Setting::put("mail.documents.{$type}", in_array($type, $d['documents'] ?? [], true) ? '1' : '0');
        }
        MailSettings::put($d);

        return back()->with('success', 'Email settings saved.');
    }

    public function test(Request $request): RedirectResponse
    {
        $d = $request->validate(['to' => ['required', 'email']]);
        MailSettings::apply();
        try {
            Mail::to($d['to'])->send(new DocumentMail('Test email from the portal', view('emails.document', ['doc' => [
                'title' => 'Test email', 'intro' => ['If you can read this, the portal can send email. Receipts, course forms and slips will reach students and applicants.'],
            ]])->render()));
        } catch (\Throwable $e) {
            return back()->with('error', 'The test email could not be sent: ' . $e->getMessage());
        }

        return back()->with('success', 'Test email sent to ' . $d['to'] . (config('mail.default') === 'log' ? ' (written to the log only, because sending is switched off).' : '.'));
    }

    /** "Email me a copy" from a student's own receipt or course form. */
    public function mine(Request $request, string $type, int $id): RedirectResponse
    {
        $student = $request->user()->student ?? abort(403);
        $n = match ($type) {
            'receipt' => ($p = Payment::with('invoice')->findOrFail($id)) && $p->invoice->payable_type === \App\Models\Student::class && $p->invoice->payable_id === $student->id && $p->status === 'successful'
                ? $this->mailer->receipt($p, $request->user()) : abort(404),
            'course_form' => ($f = RegistrationForm::findOrFail($id)) && $f->student_id === $student->id && in_array($f->status, ['submitted', 'approved'], true)
                ? $this->mailer->courseForm($f, $request->user()) : abort(404),
            default => abort(404),
        };

        return back()->with($n ? 'success' : 'error', $n ? 'A copy has been emailed to ' . $request->user()->email . '.' : 'No email was sent: your account has no email address, or these emails are switched off.');
    }
}
