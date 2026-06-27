import { useState, useRef, useEffect } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

// ─── Helpers ──────────────────────────────────────────────────────────────────

function formatDate(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const now = new Date();
    const isToday = d.toDateString() === now.toDateString();
    if (isToday) return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    const isThisYear = d.getFullYear() === now.getFullYear();
    return d.toLocaleDateString([], { month: 'short', day: 'numeric', year: isThisYear ? undefined : 'numeric' });
}

function senderName(address) {
    const m = address?.match(/^(.+?)\s*<.+>$/);
    return m ? m[1].trim() : address?.split('@')[0] ?? '';
}

function filesize(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

// ─── Compose / Reply Modal ─────────────────────────────────────────────────────

function ComposeModal({ inbox, onClose, replyTo = null }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        to: replyTo ? replyTo.from : '',
        cc: '',
        bcc: '',
        subject: replyTo ? (replyTo.subject.startsWith('Re: ') ? replyTo.subject : `Re: ${replyTo.subject}`) : '',
        html: replyTo
            ? `<br/><br/><blockquote style="border-left:3px solid #ccc;padding-left:1em;color:#555;">${replyTo.html_body ?? replyTo.text_body ?? ''}</blockquote>`
            : '',
        thread_id: replyTo?.thread_id ?? '',
        in_reply_to: replyTo?.message_id ?? '',
        references: replyTo?.message_id ?? '',
    });

    const fileRef = useRef();
    const [files, setFiles] = useState([]);

    const submit = (e) => {
        e.preventDefault();
        const fd = new FormData();
        Object.entries(data).forEach(([k, v]) => v != null && fd.append(k, v));
        files.forEach((f) => fd.append('attachments[]', f));
        router.post(route('inbox.send'), fd, {
            forceFormData: true,
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => { reset(); onClose(); },
        });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40">
            <div className="w-full max-w-3xl mx-4 bg-white rounded-xl shadow-2xl border border-gray-200 flex flex-col"
                style={{ height: '75vh' }}>
                {/* Header */}
                <div className="flex items-center justify-between px-5 py-4 bg-gray-800 rounded-t-xl text-white shrink-0">
                    <span className="font-semibold">{replyTo ? 'Reply' : 'New Message'}</span>
                    <button onClick={onClose} className="text-gray-300 hover:text-white">
                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form onSubmit={submit} className="flex flex-col flex-1 overflow-hidden">
                    {/* Fields */}
                    <div className="border-b border-gray-200 shrink-0">
                        {['to', 'cc', 'bcc'].map((field) => (
                            <div key={field} className="flex items-center border-b border-gray-100 last:border-0">
                                <span className="w-16 text-xs font-semibold text-gray-400 uppercase px-5 py-2.5 shrink-0">
                                    {field}
                                </span>
                                <input
                                    type="text"
                                    value={data[field]}
                                    onChange={(e) => setData(field, e.target.value)}
                                    placeholder={field === 'to' ? 'Recipients' : ''}
                                    className="flex-1 py-2.5 px-2 text-sm outline-none border-0 focus:ring-0"
                                    required={field === 'to'}
                                />
                            </div>
                        ))}
                        <div className="flex items-center">
                            <span className="w-16 text-xs font-semibold text-gray-400 uppercase px-5 py-2.5 shrink-0">
                                Subject
                            </span>
                            <input
                                type="text"
                                value={data.subject}
                                onChange={(e) => setData('subject', e.target.value)}
                                className="flex-1 py-2.5 px-2 text-sm outline-none border-0 focus:ring-0"
                                required
                            />
                        </div>
                    </div>

                    {/* Body */}
                    <textarea
                        className="flex-1 p-5 text-sm resize-none outline-none border-0 focus:ring-0 font-sans"
                        placeholder="Compose your message..."
                        value={data.html}
                        onChange={(e) => setData('html', e.target.value)}
                    />

                    {/* Attachments list */}
                    {files.length > 0 && (
                        <div className="px-5 pb-2 flex flex-wrap gap-2 shrink-0">
                            {files.map((f, i) => (
                                <span key={i} className="inline-flex items-center gap-1 text-xs bg-gray-100 rounded px-2 py-1">
                                    {f.name}
                                    <button type="button" onClick={() => setFiles(files.filter((_, j) => j !== i))}
                                        className="text-gray-400 hover:text-red-500">×</button>
                                </span>
                            ))}
                        </div>
                    )}

                    {/* Footer */}
                    <div className="flex items-center gap-3 px-5 py-4 border-t border-gray-200 shrink-0">
                        <button
                            type="submit"
                            disabled={processing}
                            className="px-6 py-2 bg-blue-600 text-white text-sm font-medium rounded-full hover:bg-blue-700 disabled:opacity-50"
                        >
                            {processing ? 'Sending…' : 'Send'}
                        </button>
                        <button
                            type="button"
                            onClick={() => fileRef.current?.click()}
                            className="text-gray-500 hover:text-gray-700"
                            title="Attach files"
                        >
                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                                    d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
                            </svg>
                        </button>
                        <button
                            type="button"
                            onClick={onClose}
                            className="ml-auto text-sm text-gray-400 hover:text-gray-600"
                        >
                            Discard
                        </button>
                        <input
                            ref={fileRef}
                            type="file"
                            multiple
                            className="hidden"
                            onChange={(e) => setFiles([...files, ...Array.from(e.target.files)])}
                        />
                    </div>
                </form>
            </div>
        </div>
    );
}

// ─── Attachment chip ───────────────────────────────────────────────────────────

function AttachmentChip({ att }) {
    const icons = {
        'application/pdf': '📄',
        'image/': '🖼',
        'video/': '🎬',
        'audio/': '🎵',
    };
    const icon = Object.entries(icons).find(([k]) => att.content_type?.startsWith(k))?.[1] ?? '📎';

    return (
        <a
            href={route('inbox.attachment', att.id)}
            className="inline-flex items-center gap-1.5 text-xs bg-gray-100 hover:bg-gray-200 rounded-lg px-3 py-1.5 text-gray-700 transition-colors"
        >
            <span>{icon}</span>
            <span className="font-medium">{att.filename}</span>
            <span className="text-gray-400">{filesize(att.size)}</span>
        </a>
    );
}

// ─── Message bubble ────────────────────────────────────────────────────────────

function MessageBubble({ message, inbox }) {
    const isOutbound = message.direction === 'outbound';

    return (
        <div className={`flex flex-col gap-1 ${isOutbound ? 'items-end' : 'items-start'}`}>
            <div className="flex items-center gap-2 text-xs text-gray-400">
                <span className="font-medium text-gray-600">
                    {isOutbound ? `${inbox.display_name} (you)` : senderName(message.from)}
                </span>
                <span>{formatDate(message.created_at)}</span>
            </div>

            <div className={`max-w-[85%] rounded-2xl px-4 py-3 text-sm ${
                isOutbound
                    ? 'bg-blue-600 text-white rounded-tr-sm'
                    : 'bg-white border border-gray-200 text-gray-800 rounded-tl-sm'
            }`}>
                {message.html_body ? (
                    <div
                        className="prose prose-sm max-w-none"
                        style={{ color: isOutbound ? 'white' : undefined }}
                        dangerouslySetInnerHTML={{ __html: message.html_body }}
                    />
                ) : (
                    <p className="whitespace-pre-wrap">{message.text_body}</p>
                )}
            </div>

            {message.attachments?.length > 0 && (
                <div className="flex flex-wrap gap-1.5 mt-1">
                    {message.attachments.map((att) => (
                        <AttachmentChip key={att.id} att={att} />
                    ))}
                </div>
            )}
        </div>
    );
}

// ─── Thread pane ──────────────────────────────────────────────────────────────

function ThreadPane({ thread, inbox, onReply, onClose }) {
    const bottomRef = useRef();

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [thread?.thread_id, thread?.messages?.length]);

    if (!thread) {
        return (
            <div className="flex-1 flex items-center justify-center text-gray-400">
                <div className="text-center">
                    <svg className="w-16 h-16 mx-auto mb-3 text-gray-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
                            d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                    <p className="text-sm">Select a conversation</p>
                </div>
            </div>
        );
    }

    return (
        <div className="flex-1 flex flex-col overflow-hidden">
            {/* Thread header */}
            <div className="flex items-center gap-3 px-6 py-4 border-b border-gray-200 bg-white shrink-0">
                <button onClick={onClose} className="text-gray-400 hover:text-gray-600 lg:hidden">
                    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                    </svg>
                </button>
                <div className="flex-1 min-w-0">
                    <h2 className="font-semibold text-gray-900 truncate">{thread.subject}</h2>
                    <p className="text-xs text-gray-400">{thread.messages.length} message{thread.messages.length !== 1 ? 's' : ''}</p>
                </div>
                <button
                    onClick={onReply}
                    className="flex items-center gap-1.5 text-sm font-medium text-blue-600 hover:text-blue-700"
                >
                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                            d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6" />
                    </svg>
                    Reply
                </button>
            </div>

            {/* Messages */}
            <div className="flex-1 overflow-y-auto px-6 py-4 space-y-5 bg-gray-50">
                {thread.messages.map((msg) => (
                    <MessageBubble key={msg.id} message={msg} inbox={inbox} />
                ))}
                <div ref={bottomRef} />
            </div>
        </div>
    );
}

// ─── Thread list item ─────────────────────────────────────────────────────────

function ThreadItem({ thread, isActive, onClick }) {
    return (
        <button
            onClick={onClick}
            className={`w-full text-left px-4 py-3 hover:bg-gray-50 transition-colors border-b border-gray-100 ${
                isActive ? 'bg-blue-50 border-l-2 border-l-blue-500' : ''
            }`}
        >
            <div className="flex items-center justify-between mb-0.5">
                <span className={`text-sm truncate ${thread.unread > 0 ? 'font-semibold text-gray-900' : 'text-gray-700'}`}>
                    {senderName(thread.from)}
                </span>
                <span className="text-xs text-gray-400 shrink-0 ml-2">{formatDate(thread.date)}</span>
            </div>
            <div className="flex items-center gap-2">
                <span className={`text-sm truncate ${thread.unread > 0 ? 'font-medium text-gray-800' : 'text-gray-500'}`}>
                    {thread.subject}
                </span>
                {thread.unread > 0 && (
                    <span className="shrink-0 inline-flex items-center justify-center w-5 h-5 bg-blue-600 text-white text-xs font-bold rounded-full">
                        {thread.unread}
                    </span>
                )}
            </div>
            <p className="text-xs text-gray-400 truncate mt-0.5">{thread.snippet}</p>
        </button>
    );
}

// ─── Main page ────────────────────────────────────────────────────────────────

export default function InboxIndex({ inbox, threads }) {
    const [compose, setCompose] = useState(false);
    const [replyTo, setReplyTo] = useState(null);
    const [selectedThreadId, setSelectedThreadId] = useState(null);
    const [readThreadIds, setReadThreadIds] = useState(new Set());
    const [folder, setFolder] = useState('inbox');

    const visibleThreads = threads.filter((t) => {
        if (folder === 'inbox') return t.has_inbound;
        if (folder === 'sent')  return t.has_outbound;
        return true;
    });

    const selectedThread = visibleThreads.find((t) => t.thread_id === selectedThreadId) ?? null;

    const openThread = (threadId) => {
        setSelectedThreadId(threadId);
        setReadThreadIds((prev) => new Set([...prev, threadId]));
        fetch(route('inbox.threads.read', threadId), {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
        });
    };

    const closeThread = () => setSelectedThreadId(null);

    const switchFolder = (f) => {
        setFolder(f);
        setSelectedThreadId(null);
    };

    const handleReply = () => {
        if (!selectedThread) return;
        const lastInbound = [...selectedThread.messages].reverse().find((m) => m.direction === 'inbound');
        setReplyTo(lastInbound ?? selectedThread.messages.at(-1));
        setCompose(false);
    };

    const folders = [
        {
            key: 'inbox',
            label: 'Inbox',
            icon: (
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                    d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0H4m16 0l-2-5H6l-2 5" />
            ),
            badge: Math.max(0, inbox.unread_count - [...readThreadIds].filter(id => threads.find(t => t.thread_id === id && t.unread > 0)).length),
        },
        {
            key: 'sent',
            label: 'Sent',
            icon: (
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                    d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
            ),
        },
        {
            key: 'all',
            label: 'All Mail',
            icon: (
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2}
                    d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
            ),
        },
    ];

    return (
        <AuthenticatedLayout>
            <Head title={`Inbox — ${inbox.email_address}`} />

            <div className="flex h-[calc(100vh-64px)] overflow-hidden bg-gray-50">
                {/* ── Sidebar ── */}
                <div className="w-56 shrink-0 bg-white border-r border-gray-200 flex flex-col">
                    {/* Account */}
                    <div className="px-4 py-4 border-b border-gray-100">
                        <div className="flex items-center gap-2">
                            <div className="w-8 h-8 rounded-full bg-blue-600 text-white text-xs font-bold flex items-center justify-center shrink-0">
                                {inbox.display_name.charAt(0).toUpperCase()}
                            </div>
                            <div className="min-w-0">
                                <p className="text-sm font-medium text-gray-900 truncate">{inbox.display_name}</p>
                                <p className="text-xs text-gray-400 truncate">{inbox.email_address}</p>
                            </div>
                        </div>
                    </div>

                    {/* Compose */}
                    <div className="px-3 py-3">
                        <button
                            onClick={() => { setReplyTo(null); setCompose(true); }}
                            className="w-full flex items-center gap-2 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-full hover:bg-blue-700 transition-colors"
                        >
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                            </svg>
                            Compose
                        </button>
                    </div>

                    {/* Folders */}
                    <nav className="flex-1 px-2 space-y-0.5">
                        {folders.map((f) => (
                            <button
                                key={f.key}
                                onClick={() => switchFolder(f.key)}
                                className={`w-full flex items-center justify-between gap-2 px-3 py-2 rounded-lg text-sm transition-colors ${
                                    folder === f.key
                                        ? 'bg-blue-50 text-blue-700 font-medium'
                                        : 'text-gray-600 hover:bg-gray-50'
                                }`}
                            >
                                <span className="flex items-center gap-2">
                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        {f.icon}
                                    </svg>
                                    {f.label}
                                </span>
                                {f.badge > 0 && (
                                    <span className="text-xs font-bold text-blue-600">{f.badge}</span>
                                )}
                            </button>
                        ))}
                    </nav>
                </div>

                {/* ── Thread list ── */}
                <div className={`w-80 shrink-0 bg-white border-r border-gray-200 overflow-y-auto ${selectedThread ? 'hidden lg:flex lg:flex-col' : 'flex flex-col'}`}>
                    <div className="px-4 py-3 border-b border-gray-100">
                        <h1 className="font-semibold text-gray-800 capitalize">{folder === 'all' ? 'All Mail' : folder}</h1>
                        <p className="text-xs text-gray-400">{visibleThreads.length} conversation{visibleThreads.length !== 1 ? 's' : ''}</p>
                    </div>

                    {visibleThreads.length === 0 ? (
                        <div className="flex-1 flex items-center justify-center p-8 text-center text-gray-400">
                            <div>
                                <svg className="w-12 h-12 mx-auto mb-2 text-gray-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
                                        d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0H4" />
                                </svg>
                                <p className="text-sm">No messages</p>
                            </div>
                        </div>
                    ) : (
                        visibleThreads.map((t) => (
                            <ThreadItem
                                key={t.thread_id}
                                thread={{ ...t, unread: readThreadIds.has(t.thread_id) ? 0 : t.unread }}
                                isActive={selectedThreadId === t.thread_id}
                                onClick={() => openThread(t.thread_id)}
                            />
                        ))
                    )}
                </div>

                {/* ── Thread / message pane ── */}
                <ThreadPane
                    thread={selectedThread}
                    inbox={inbox}
                    onReply={handleReply}
                    onClose={closeThread}
                />
            </div>

            {/* Compose / Reply modal */}
            {(compose || replyTo) && (
                <ComposeModal
                    inbox={inbox}
                    replyTo={replyTo}
                    onClose={() => { setCompose(false); setReplyTo(null); }}
                />
            )}
        </AuthenticatedLayout>
    );
}
