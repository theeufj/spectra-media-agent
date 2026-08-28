import { useCallback, useRef, useState } from 'react';
import { EditorContent, useEditor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';
import TextAlign from '@tiptap/extension-text-align';
import { Color, FontFamily, FontSize, TextStyle } from '@tiptap/extension-text-style';

/**
 * Rich-text editing for one email body.
 *
 * The toolbar is deliberately short. Every control here maps to something the
 * server-side sanitiser keeps and that Outlook actually renders — offering a
 * button whose formatting is stripped on send would be worse than not offering
 * it, because the admin would believe it worked.
 *
 * Fonts are restricted to the web-safe stacks for the same reason: a webfont
 * in an email falls back to Times New Roman in most desktop clients, so the
 * choice would only be visible in the preview.
 */

const FONTS = [
    { label: 'Default (system)', value: '' },
    { label: 'Arial', value: 'Arial, Helvetica, sans-serif' },
    { label: 'Helvetica', value: 'Helvetica, Arial, sans-serif' },
    { label: 'Georgia', value: 'Georgia, serif' },
    { label: 'Times New Roman', value: "'Times New Roman', Times, serif" },
    { label: 'Trebuchet MS', value: "'Trebuchet MS', Helvetica, sans-serif" },
    { label: 'Verdana', value: 'Verdana, Geneva, sans-serif' },
    { label: 'Courier New', value: "'Courier New', Courier, monospace" },
];

const SIZES = ['', '12px', '14px', '15px', '16px', '18px', '20px', '24px', '28px', '32px'];

const Button = ({ active, disabled, title, onClick, children }) => (
    <button
        type="button"
        title={title}
        disabled={disabled}
        onClick={onClick}
        className={`rounded px-2 py-1 text-sm leading-none transition disabled:opacity-40 ${
            active ? 'bg-brand-primary/20 text-brand-darker' : 'text-gray-600 hover:bg-gray-100'
        }`}
    >
        {children}
    </button>
);

const Divider = () => <span className="mx-1 h-5 w-px bg-gray-200" aria-hidden="true" />;

export default function EmailEditor({ value, onChange, uploadUrl, csrf }) {
    const fileInput = useRef(null);
    const [uploading, setUploading] = useState(false);
    const [uploadError, setUploadError] = useState(null);

    const editor = useEditor({
        extensions: [
            StarterKit.configure({
                // Neither survives an email client, and code blocks in
                // particular arrive as unstyled text with the markup showing.
                codeBlock: false,
                code: false,
                link: {
                    openOnClick: false,
                    autolink: true,
                    // Matches the sanitiser's scheme allowlist. Letting the
                    // editor create a link the server then strips is the kind
                    // of mismatch that reads as a bug in the save.
                    protocols: ['http', 'https', 'mailto', 'tel'],
                },
            }),
            TextStyle,
            Color,
            FontFamily,
            FontSize,
            Image.configure({ inline: false, allowBase64: false }),
            TextAlign.configure({ types: ['heading', 'paragraph'] }),
        ],
        content: value || '',
        // The stored value is the source of truth for the preview and the
        // send, so it updates on every keystroke rather than on blur.
        onUpdate: ({ editor }) => onChange(editor.getHTML()),
        editorProps: {
            attributes: {
                class: 'email-editor-content min-h-[260px] px-4 py-3 focus:outline-none',
            },
        },
    });

    const setLink = useCallback(() => {
        if (!editor) return;

        const previous = editor.getAttributes('link').href ?? '';
        const url = window.prompt('Link URL', previous);

        if (url === null) return;

        if (url === '') {
            editor.chain().focus().extendMarkRange('link').unsetLink().run();
            return;
        }

        editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
    }, [editor]);

    const upload = useCallback(
        async (file) => {
            if (!file || !editor) return;

            setUploading(true);
            setUploadError(null);

            const body = new FormData();
            body.append('image', file);

            try {
                // FormData rather than fetchJson: that helper sets a JSON
                // content type, and a multipart upload needs the browser to
                // set its own boundary.
                const response = await fetch(uploadUrl, {
                    method: 'POST',
                    body,
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                });

                if (!response.ok) {
                    const problem = await response.json().catch(() => ({}));
                    throw new Error(problem?.errors?.image?.[0] ?? problem?.message ?? `Upload failed (${response.status})`);
                }

                const { url } = await response.json();

                editor.chain().focus().setImage({ src: url }).run();
            } catch (error) {
                setUploadError(error.message);
            } finally {
                setUploading(false);
                if (fileInput.current) fileInput.current.value = '';
            }
        },
        [editor, uploadUrl, csrf],
    );

    if (!editor) return null;

    const can = editor.can().chain().focus();

    return (
        <div className="rounded-lg border border-gray-300 focus-within:border-brand-primary focus-within:ring-1 focus-within:ring-brand-primary">
            <div className="flex flex-wrap items-center gap-0.5 border-b border-gray-200 bg-gray-50 px-2 py-1.5">
                <Button title="Bold" active={editor.isActive('bold')} disabled={!can.toggleBold().run()} onClick={() => editor.chain().focus().toggleBold().run()}>
                    <span className="font-bold">B</span>
                </Button>
                <Button title="Italic" active={editor.isActive('italic')} onClick={() => editor.chain().focus().toggleItalic().run()}>
                    <span className="italic">I</span>
                </Button>
                <Button title="Underline" active={editor.isActive('underline')} onClick={() => editor.chain().focus().toggleUnderline().run()}>
                    <span className="underline">U</span>
                </Button>
                <Button title="Strikethrough" active={editor.isActive('strike')} onClick={() => editor.chain().focus().toggleStrike().run()}>
                    <span className="line-through">S</span>
                </Button>

                <Divider />

                <Button title="Heading" active={editor.isActive('heading', { level: 2 })} onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()}>H1</Button>
                <Button title="Subheading" active={editor.isActive('heading', { level: 3 })} onClick={() => editor.chain().focus().toggleHeading({ level: 3 }).run()}>H2</Button>
                <Button title="Bulleted list" active={editor.isActive('bulletList')} onClick={() => editor.chain().focus().toggleBulletList().run()}>• List</Button>
                <Button title="Numbered list" active={editor.isActive('orderedList')} onClick={() => editor.chain().focus().toggleOrderedList().run()}>1. List</Button>
                <Button title="Quote" active={editor.isActive('blockquote')} onClick={() => editor.chain().focus().toggleBlockquote().run()}>❝</Button>
                <Button title="Divider" onClick={() => editor.chain().focus().setHorizontalRule().run()}>⎯</Button>

                <Divider />

                <Button title="Align left" active={editor.isActive({ textAlign: 'left' })} onClick={() => editor.chain().focus().setTextAlign('left').run()}>⯇</Button>
                <Button title="Centre" active={editor.isActive({ textAlign: 'center' })} onClick={() => editor.chain().focus().setTextAlign('center').run()}>≡</Button>
                <Button title="Align right" active={editor.isActive({ textAlign: 'right' })} onClick={() => editor.chain().focus().setTextAlign('right').run()}>⯈</Button>

                <Divider />

                <Button title="Link" active={editor.isActive('link')} onClick={setLink}>🔗</Button>
                <Button title="Insert image" disabled={uploading} onClick={() => fileInput.current?.click()}>
                    {uploading ? '…' : '🖼'}
                </Button>
                <input
                    ref={fileInput}
                    type="file"
                    accept="image/png,image/jpeg,image/gif,image/webp"
                    className="hidden"
                    onChange={(e) => upload(e.target.files?.[0])}
                />

                <Divider />

                <select
                    title="Font"
                    value={editor.getAttributes('textStyle').fontFamily ?? ''}
                    onChange={(e) =>
                        e.target.value
                            ? editor.chain().focus().setFontFamily(e.target.value).run()
                            : editor.chain().focus().unsetFontFamily().run()
                    }
                    className="rounded border-gray-300 py-1 text-xs"
                >
                    {FONTS.map((f) => <option key={f.label} value={f.value}>{f.label}</option>)}
                </select>

                <select
                    title="Size"
                    value={editor.getAttributes('textStyle').fontSize ?? ''}
                    onChange={(e) =>
                        e.target.value
                            ? editor.chain().focus().setFontSize(e.target.value).run()
                            : editor.chain().focus().unsetFontSize().run()
                    }
                    className="rounded border-gray-300 py-1 text-xs"
                >
                    {SIZES.map((s) => <option key={s || 'default'} value={s}>{s || 'Size'}</option>)}
                </select>

                <input
                    type="color"
                    title="Text colour"
                    value={editor.getAttributes('textStyle').color ?? '#2d3748'}
                    onChange={(e) => editor.chain().focus().setColor(e.target.value).run()}
                    className="h-7 w-7 cursor-pointer rounded border border-gray-300 bg-white p-0.5"
                />

                <Divider />

                <Button title="Clear formatting" onClick={() => editor.chain().focus().unsetAllMarks().clearNodes().run()}>✕ format</Button>
            </div>

            {uploadError && (
                <p className="border-b border-red-100 bg-red-50 px-4 py-2 text-xs text-red-700">{uploadError}</p>
            )}

            <EditorContent editor={editor} />
        </div>
    );
}
