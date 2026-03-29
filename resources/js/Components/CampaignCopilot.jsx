import { useState, useRef, useEffect } from 'react';

const SUGGESTED_QUESTIONS = [
    "How is my campaign performing?",
    "Why did my CPA spike?",
    "Should I increase my budget?",
    "How am I doing vs benchmarks?",
    "What should I optimize first?",
    "Are my ad creatives working?",
];

export default function CampaignCopilot({ campaignId, isOpen, onClose }) {
    const [messages, setMessages] = useState([]);
    const [input, setInput] = useState('');
    const [isLoading, setIsLoading] = useState(false);
    const [historyLoaded, setHistoryLoaded] = useState(false);
    const messagesEndRef = useRef(null);
    const inputRef = useRef(null);

    // Load conversation history on first open
    useEffect(() => {
        if (isOpen && !historyLoaded) {
            loadHistory();
        }
        if (isOpen && inputRef.current) {
            inputRef.current.focus();
        }
    }, [isOpen]);

    // Auto-scroll on new messages
    useEffect(() => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages]);

    const loadHistory = async () => {
        try {
            const res = await fetch(`/api/campaigns/${campaignId}/chat/history`, {
                credentials: 'include',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (res.ok) {
                const data = await res.json();
                setMessages(data.messages || []);
            }
        } catch (e) {
            console.error('Failed to load chat history:', e);
        }
        setHistoryLoaded(true);
    };

    const sendMessage = async (text) => {
        if (!text.trim() || isLoading) return;

        const userMsg = { role: 'user', content: text.trim(), timestamp: new Date().toISOString() };
        setMessages(prev => [...prev, userMsg]);
        setInput('');
        setIsLoading(true);

        try {
            const res = await fetch(`/api/campaigns/${campaignId}/chat`, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                body: JSON.stringify({ message: text.trim() }),
            });

            if (res.ok) {
                const data = await res.json();
                const assistantMsg = { role: 'assistant', content: data.message, timestamp: new Date().toISOString() };
                setMessages(prev => [...prev, assistantMsg]);
            } else {
                setMessages(prev => [...prev, {
                    role: 'assistant',
                    content: 'Sorry, I encountered an error. Please try again.',
                    timestamp: new Date().toISOString(),
                    error: true,
                }]);
            }
        } catch (e) {
            setMessages(prev => [...prev, {
                role: 'assistant',
                content: 'Connection error. Please check your network and try again.',
                timestamp: new Date().toISOString(),
                error: true,
            }]);
        }

        setIsLoading(false);
    };

    const clearChat = async () => {
        try {
            await fetch(`/api/campaigns/${campaignId}/chat`, {
                method: 'DELETE',
                credentials: 'include',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
            });
        } catch (e) { console.error('Failed to clear chat:', e); }
        setMessages([]);
    };

    const handleKeyDown = (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage(input);
        }
    };

    if (!isOpen) return null;

    return (
        <div className="fixed inset-y-0 right-0 w-full sm:w-[420px] bg-white shadow-2xl z-50 flex flex-col border-l border-gray-200">
            {/* Header */}
            <div className="flex items-center justify-between px-4 py-3 bg-gradient-to-r from-flame-orange-600 to-flame-orange-700 text-white">
                <div className="flex items-center gap-2">
                    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                    </svg>
                    <span className="font-semibold">Campaign Copilot</span>
                </div>
                <div className="flex items-center gap-2">
                    <button onClick={clearChat} className="text-white/70 hover:text-white text-xs px-2 py-1 rounded hover:bg-white/10 transition" title="Clear chat">
                        Clear
                    </button>
                    <button onClick={onClose} className="text-white/70 hover:text-white p-1 rounded hover:bg-white/10 transition">
                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>

            {/* Messages */}
            <div className="flex-1 overflow-y-auto px-4 py-4 space-y-4">
                {messages.length === 0 && !isLoading && (
                    <div className="text-center py-8">
                        <div className="text-4xl mb-3">💬</div>
                        <p className="text-gray-600 font-medium mb-1">Ask me anything about your campaign</p>
                        <p className="text-gray-400 text-sm mb-6">I have access to your performance data, strategies, and A/B tests.</p>
                        <div className="space-y-2">
                            {SUGGESTED_QUESTIONS.map((q, i) => (
                                <button
                                    key={i}
                                    onClick={() => sendMessage(q)}
                                    className="block w-full text-left px-3 py-2 text-sm text-flame-orange-700 bg-flame-orange-50 rounded-lg hover:bg-flame-orange-100 transition"
                                >
                                    {q}
                                </button>
                            ))}
                        </div>
                    </div>
                )}

                {messages.map((msg, idx) => (
                    <div key={idx} className={`flex ${msg.role === 'user' ? 'justify-end' : 'justify-start'}`}>
                        <div className={`max-w-[85%] rounded-2xl px-4 py-2.5 text-sm leading-relaxed ${
                            msg.role === 'user'
                                ? 'bg-flame-orange-600 text-white rounded-br-md'
                                : msg.error
                                    ? 'bg-red-50 text-red-700 border border-red-200 rounded-bl-md'
                                    : 'bg-gray-100 text-gray-800 rounded-bl-md'
                        }`}>
                            <MessageContent content={msg.content} />
                        </div>
                    </div>
                ))}

                {isLoading && (
                    <div className="flex justify-start">
                        <div className="bg-gray-100 rounded-2xl rounded-bl-md px-4 py-3">
                            <div className="flex items-center gap-1">
                                <div className="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style={{ animationDelay: '0ms' }} />
                                <div className="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style={{ animationDelay: '150ms' }} />
                                <div className="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style={{ animationDelay: '300ms' }} />
                            </div>
                        </div>
                    </div>
                )}

                <div ref={messagesEndRef} />
            </div>

            {/* Input */}
            <div className="border-t border-gray-200 px-4 py-3">
                <div className="flex items-end gap-2">
                    <textarea
                        ref={inputRef}
                        value={input}
                        onChange={e => setInput(e.target.value)}
                        onKeyDown={handleKeyDown}
                        placeholder="Ask about your campaign..."
                        rows={1}
                        className="flex-1 resize-none rounded-xl border border-gray-300 px-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-flame-orange-500 focus:border-transparent"
                        style={{ maxHeight: '120px' }}
                    />
                    <button
                        onClick={() => sendMessage(input)}
                        disabled={!input.trim() || isLoading}
                        className="flex-shrink-0 p-2.5 bg-flame-orange-600 text-white rounded-xl hover:bg-flame-orange-700 disabled:opacity-40 disabled:cursor-not-allowed transition"
                    >
                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    );
}

/**
 * Renders markdown-lite content from the copilot.
 * Handles bold, lists, and action items (⚡ prefix).
 */
function MessageContent({ content }) {
    if (!content) return null;

    const lines = content.split('\n');
    const elements = [];

    lines.forEach((line, i) => {
        if (line.startsWith('⚡')) {
            elements.push(
                <div key={i} className="my-1 px-2 py-1.5 bg-yellow-50 border border-yellow-200 rounded-lg text-yellow-800 text-xs font-medium">
                    {line}
                </div>
            );
        } else if (line.startsWith('- ') || line.startsWith('• ')) {
            elements.push(<div key={i} className="ml-3 before:content-['•'] before:mr-2 before:text-gray-400">{line.substring(2)}</div>);
        } else if (line.startsWith('## ')) {
            elements.push(<div key={i} className="font-bold text-base mt-2 mb-1">{line.substring(3)}</div>);
        } else if (line.startsWith('### ')) {
            elements.push(<div key={i} className="font-semibold mt-1.5 mb-0.5">{line.substring(4)}</div>);
        } else if (line.trim() === '') {
            elements.push(<div key={i} className="h-2" />);
        } else {
            // Handle inline bold
            const parts = line.split(/(\*\*.*?\*\*)/g);
            elements.push(
                <div key={i}>
                    {parts.map((part, j) =>
                        part.startsWith('**') && part.endsWith('**')
                            ? <strong key={j}>{part.slice(2, -2)}</strong>
                            : <span key={j}>{part}</span>
                    )}
                </div>
            );
        }
    });

    return <>{elements}</>;
}
