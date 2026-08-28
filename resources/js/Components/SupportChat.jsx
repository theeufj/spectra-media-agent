import { useState, useRef, useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import { fetchJson, HttpError } from '@/utils/http';

/**
 * The in-app support chat.
 *
 * Every message raises (or continues) a real support ticket and emails the
 * admins, so the assistant's answer is a courtesy, not the resolution. The copy
 * says so explicitly — a customer who believes the bot settled their billing
 * question and then gets a contradicting email is worse off than one who was
 * told from the start that a human is coming.
 */
export default function SupportChat() {
    const { auth } = usePage().props;

    const [open, setOpen] = useState(false);
    const [messages, setMessages] = useState([]);
    const [draft, setDraft] = useState('');
    const [sending, setSending] = useState(false);
    const [ticketId, setTicketId] = useState(null);
    const [closed, setClosed] = useState(false);

    const scrollRef = useRef(null);
    const inputRef = useRef(null);

    // Keep the newest message in view as the conversation grows.
    useEffect(() => {
        scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: 'smooth' });
    }, [messages, sending]);

    useEffect(() => {
        if (open) inputRef.current?.focus();
    }, [open]);

    // Logged-out visitors have no ticket to attach to, and support_tickets
    // requires a user. The widget simply is not there.
    if (!auth?.user) return null;

    const send = async (e) => {
        e?.preventDefault();

        const text = draft.trim();
        if (!text || sending || closed) return;

        setMessages((m) => [...m, { role: 'customer', text }]);
        setDraft('');
        setSending(true);

        try {
            const res = await fetchJson(route('support.chat.send'), {
                method: 'POST',
                json: { message: text, ticket_id: ticketId },
            });

            setTicketId(res.ticket_id ?? null);
            setClosed(Boolean(res.closed));
            setMessages((m) => [...m, { role: 'assistant', text: res.reply }]);
        } catch (error) {
            // The message may well have been recorded before the failure, so
            // this must not tell the customer it was lost — that invites a
            // duplicate ticket. Rate limiting gets its own wording because it
            // is the one case where retrying shortly actually works.
            const tooFast = error instanceof HttpError && error.status === 429;

            setMessages((m) => [...m, {
                role: 'assistant',
                text: tooFast
                    ? "You're sending messages faster than I can keep up. Give it a moment and try again."
                    : "I couldn't get a reply back just then. If your message reached us the team already has it — otherwise email us and we'll pick it up.",
            }]);
        } finally {
            setSending(false);
        }
    };

    return (
        <>
            {/* Launcher */}
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                aria-label={open ? 'Close support chat' : 'Open support chat'}
                aria-expanded={open}
                className="fixed bottom-5 right-5 z-50 flex h-14 w-14 items-center justify-center rounded-full bg-brand-primary text-white shadow-lg transition-colors hover:bg-brand-dark focus:outline-none focus:ring-2 focus:ring-brand-primary/70 focus:ring-offset-2"
            >
                {open ? (
                    <svg className="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                ) : (
                    <svg className="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4-.8L3 20l1.4-3.5A7.9 7.9 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                    </svg>
                )}
            </button>

            {open && (
                <div
                    role="dialog"
                    aria-label="Support chat"
                    className="fixed bottom-24 right-5 z-50 flex h-[30rem] w-[min(23rem,calc(100vw-2.5rem))] flex-col overflow-hidden rounded-xl bg-white shadow-2xl ring-1 ring-black/10"
                >
                    <div className="bg-brand-primary px-4 py-3 text-white">
                        <h2 className="text-sm font-semibold">Support</h2>
                        <p className="text-xs text-white/90">
                            Ask anything — your question goes straight to the team either way.
                        </p>
                    </div>

                    <div ref={scrollRef} className="flex-1 space-y-3 overflow-y-auto bg-gray-50 p-4">
                        {messages.length === 0 && (
                            <p className="text-sm text-gray-500">
                                Hi {auth.user.name?.split(' ')[0] || 'there'} — what can we help with?
                                I'll answer what I can, and a human will follow up by email.
                            </p>
                        )}

                        {messages.map((m, i) => (
                            <div key={i} className={m.role === 'customer' ? 'flex justify-end' : 'flex justify-start'}>
                                <div
                                    className={`max-w-[85%] whitespace-pre-wrap rounded-lg px-3 py-2 text-sm ${
                                        m.role === 'customer'
                                            ? 'bg-brand-primary text-white'
                                            : 'bg-white text-gray-800 ring-1 ring-gray-200'
                                    }`}
                                >
                                    {m.text}
                                </div>
                            </div>
                        ))}

                        {sending && (
                            <div className="flex justify-start">
                                <div className="rounded-lg bg-white px-3 py-2 text-sm text-gray-400 ring-1 ring-gray-200">
                                    Typing…
                                </div>
                            </div>
                        )}

                        {ticketId && (
                            <p className="pt-1 text-center text-xs text-gray-400">
                                Saved as ticket #{ticketId}. The team has been notified.
                            </p>
                        )}
                    </div>

                    <form onSubmit={send} className="border-t border-gray-200 bg-white p-3">
                        <div className="flex items-end gap-2">
                            <textarea
                                ref={inputRef}
                                rows={2}
                                value={draft}
                                disabled={closed}
                                onChange={(e) => setDraft(e.target.value)}
                                onKeyDown={(e) => {
                                    // Enter sends; Shift+Enter breaks the line.
                                    if (e.key === 'Enter' && !e.shiftKey) {
                                        e.preventDefault();
                                        send();
                                    }
                                }}
                                maxLength={2000}
                                placeholder={closed ? 'This conversation is with the team now.' : 'Type your question…'}
                                className="flex-1 resize-none rounded-lg border-gray-300 text-sm focus:border-brand-primary focus:ring-brand-primary disabled:bg-gray-100"
                            />
                            <button
                                type="submit"
                                disabled={sending || closed || !draft.trim()}
                                className="rounded-lg bg-brand-primary px-3 py-2 text-sm font-medium text-white transition-colors hover:bg-brand-dark disabled:cursor-not-allowed disabled:bg-gray-300"
                            >
                                Send
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </>
    );
}
