<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Notifications\StoreMail;
use App\Services\SettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class ContactController extends Controller
{
    public function store(Request $r, SettingService $s)
    {
        $d = $r->validate(['name' => 'required|string|max:120', 'email' => 'required|email:rfc|max:254', 'subject' => 'required|string|max:180',
            'order_reference' => 'nullable|string|max:80', 'message' => 'required|string|min:10|max:5000']);
        $message = ContactMessage::create($d);
        $support = $s->get('support_email');
        if ($support) {
            Notification::route('mail', $support)->notify(new StoreMail('New customer inquiry #'.$message->id, [$d['name'], $d['subject']]));
        }

        return response()->json(['message' => 'Your message has been received.'], 201);
    }
}
