<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function index()
    {
        return ContactMessage::latest('id')->paginate(25);
    }

    public function show(ContactMessage $message)
    {
        return ['data' => $message];
    }

    public function update(Request $r, ContactMessage $message)
    {
        $message->update($r->validate(['status' => 'required|in:new,in_progress,closed', 'admin_note' => 'nullable|string|max:5000']));

        return ['data' => $message];
    }
}
