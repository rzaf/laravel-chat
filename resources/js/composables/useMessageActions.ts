import { ref } from 'vue';
import type { Ref } from 'vue';
import { useContextMenu } from '@/composables/useContextMenu';
import { useConfirm } from '@/composables/useConfirm.ts';
import { sameId } from '@/composables/useMessages';
import type { Message, MessageRow } from '@/types';

export type MenuAction = 'reply' | 'edit' | 'forward' | 'copy' | 'select' | 'delete';

interface Options {
    /** The composer text (edit mode pre-fills it). */
    draft: Ref<string>;
    findMessage: (id: unknown) => Message | undefined;
    /** Deletes on the server and removes locally (`useMessages().remove`). */
    removeMessage: (id: string | number, deleteForEveryone: boolean) => Promise<void>;
    openForward: (message: Message) => void;
    /** Called when a reply / edit starts so the textarea gets focus. */
    focusComposer?: () => void;
}

/**
 * Per-message context menu and the actions it triggers, plus the
 * reply / edit state shown above the composer.
 */
export function useMessageActions({ draft, findMessage, removeMessage, openForward, focusComposer }: Options) {
    const { isOpen, x, y, open, close } = useContextMenu();
    const { confirm } = useConfirm();

    const activeMenuMessage = ref<MessageRow | null>(null);
    const replyingToMessage = ref<Message | null>(null);
    const editingMessage = ref<Message | null>(null);

    /* ---------------------------------------------------- reply / edit state */

    function startReply(message: Message): void {
        replyingToMessage.value = message;
        editingMessage.value = null; // can't edit and reply simultaneously
        focusComposer?.();
    }

    function startEdit(message: Message): void {
        editingMessage.value = message;
        draft.value = message.content;
        replyingToMessage.value = null; // can't reply and edit simultaneously
        focusComposer?.();
    }

    function cancelReply(): void {
        replyingToMessage.value = null;
    }

    function cancelEdit(): void {
        editingMessage.value = null;
        draft.value = '';
    }

    /** Drop reply/edit context (e.g. when switching chats). Leaves the draft untouched. */
    function resetComposerContext(): void {
        replyingToMessage.value = null;
        editingMessage.value = null;
    }

    /* ------------------------------------------------------------ menu state */

    function handleOpenMessageMenu(message: MessageRow, event: MouseEvent): void {
        activeMenuMessage.value = message;
        open(event);
    }

    function handleCloseMenu(): void {
        close();
        activeMenuMessage.value = null;
    }

    /* --------------------------------------------------------------- actions */

    function copyText(text: string): void {
        navigator.clipboard.writeText(text).catch((err) => {
            console.error('Failed to copy text', err);
        });
    }

    /**
     * Ask for confirmation, then delete. Resolves `false` when the user
     * cancelled (the menu is then left as-is).
     */
    async function confirmAndDelete(msg: MessageRow, showDeleteForEveryone: boolean = true): Promise<boolean> {
        const res = await confirm({
            title: 'Delete Message',
            message: `Are you sure to delete the message ?`,
            confirmText: 'Delete',
            variant: 'danger',
            checkboxLabel: showDeleteForEveryone ? 'Delete for everyone' : undefined,
        });

        const confirmed = typeof res === 'boolean' ? res : res.confirmed;
        const deleteForEveryone = typeof res === 'object' ? res.checkbox : false;

        if (!confirmed || !msg.id) return false;

        try {
            await removeMessage(msg.id, showDeleteForEveryone ? deleteForEveryone : true);
            if (replyingToMessage.value && sameId(replyingToMessage.value.id, msg.id)) {
                cancelReply();
            }
            if (editingMessage.value && sameId(editingMessage.value.id, msg.id)) {
                cancelEdit();
            }
        } catch (error) {
            console.error('Failed to delete message', error);
        }
        return true;
    }

    async function handleMenuAction(action: MenuAction, showDeleteForEveryone: boolean = true): Promise<void> {
        const msg = activeMenuMessage.value;
        if (!msg) return;

        const fullMessage = findMessage(msg.id) ?? null;

        switch (action) {
            case 'reply':
                if (fullMessage) startReply(fullMessage);
                break;
            case 'edit':
                if (fullMessage && msg.isSent) startEdit(fullMessage);
                break;
            case 'forward':
                if (fullMessage) openForward(fullMessage);
                break;
            case 'copy':
                if (msg.text) copyText(msg.text);
                break;
            case 'select':
                console.log('select message:', msg);
                break;
            case 'delete':
                if (!(await confirmAndDelete(msg, showDeleteForEveryone))) return;
                break;
        }
        handleCloseMenu();
    }

    return {
        // context menu position / visibility
        isOpen,
        x,
        y,
        activeMenuMessage,
        handleOpenMessageMenu,
        handleCloseMenu,
        handleMenuAction,
        // composer context
        replyingToMessage,
        editingMessage,
        cancelReply,
        cancelEdit,
        resetComposerContext,
    };
}