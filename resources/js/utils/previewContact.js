// The preview and registration screens share this tab's Inertia runtime. Keep
// contact details out of links, browser storage, and unrelated signups.
let contact = null;

export function rememberPreviewContact(url, name, email) {
    contact = { url, name, email };
}

export function previewContactFor(url) {
    return url && contact?.url === url ? { name: contact.name, email: contact.email } : {};
}
