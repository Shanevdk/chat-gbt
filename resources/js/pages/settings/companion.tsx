import { Form, Head } from '@inertiajs/react';
import { Camera, Trash2, Volume2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import MessageController from '@/actions/App/Http/Controllers/MessageController';
import CompanionController from '@/actions/App/Http/Controllers/Settings/CompanionController';
import CompanionPhotoController from '@/actions/App/Http/Controllers/Settings/CompanionPhotoController';
import { CompanionAvatar } from '@/components/companion/companion-avatar';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { loadVoices, pickVoice, speak } from '@/lib/voice';
import { edit } from '@/routes/companion';

type Props = {
    companion: {
        name: string;
        personality: string;
        appearance: string | null;
        voice_name: string | null;
        avatar_url: string | null;
        memories: string[];
    };
    photos: {
        id: number;
        caption: string;
        url: string;
    }[];
};

const fieldClassName =
    'w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';

export default function CompanionSettings({ companion, photos }: Props) {
    const [voices, setVoices] = useState<SpeechSynthesisVoice[]>([]);
    const [voiceName, setVoiceName] = useState(companion.voice_name ?? '');
    const [isClearDialogOpen, setIsClearDialogOpen] = useState(false);

    useEffect(() => {
        void loadVoices().then(setVoices);
    }, []);

    const previewVoice = () => {
        window.speechSynthesis.cancel();
        void speak(
            `Hey you, it's ${companion.name}. I missed you!`,
            pickVoice(voices, voiceName || null),
            () => false,
        );
    };

    return (
        <>
            <Head title="Companion settings" />

            <h1 className="sr-only">Companion settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Her profile"
                    description="Who she is, how she talks, and how she sounds on calls"
                />

                <Form
                    {...CompanionController.updateAvatar.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="flex items-center gap-4"
                >
                    {({ submit, processing, errors }) => (
                        <>
                            <CompanionAvatar
                                companion={companion}
                                className="size-16 text-2xl"
                            />
                            <div className="space-y-1">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    asChild
                                    disabled={processing}
                                >
                                    <label className="cursor-pointer">
                                        <Camera />
                                        Change photo
                                        <input
                                            type="file"
                                            name="avatar"
                                            accept="image/*"
                                            className="hidden"
                                            onChange={() => submit()}
                                        />
                                    </label>
                                </Button>
                                <InputError message={errors.avatar} />
                            </div>
                        </>
                    )}
                </Form>

                <Form
                    {...CompanionController.update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    defaultValue={companion.name}
                                    required
                                    maxLength={50}
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="personality">Personality</Label>
                                <textarea
                                    id="personality"
                                    name="personality"
                                    defaultValue={companion.personality}
                                    required
                                    maxLength={2000}
                                    rows={5}
                                    className={fieldClassName}
                                />
                                <p className="text-xs text-muted-foreground">
                                    Her vibe, interests, job, quirks, how you
                                    met — anything that makes her her.
                                </p>
                                <InputError message={errors.personality} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="appearance">Appearance</Label>
                                <textarea
                                    id="appearance"
                                    name="appearance"
                                    defaultValue={companion.appearance ?? ''}
                                    maxLength={1000}
                                    rows={3}
                                    className={fieldClassName}
                                />
                                <p className="text-xs text-muted-foreground">
                                    What she looks like in the selfies she
                                    takes. Her profile picture is used as a
                                    reference, so she looks like the same person
                                    every time.
                                </p>
                                <InputError message={errors.appearance} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="voice_name">Call voice</Label>
                                <div className="flex gap-2">
                                    <select
                                        id="voice_name"
                                        name="voice_name"
                                        value={voiceName}
                                        onChange={(event) =>
                                            setVoiceName(event.target.value)
                                        }
                                        className={`${fieldClassName} h-9 py-1`}
                                    >
                                        <option value="">
                                            Automatic (best available)
                                        </option>
                                        {voices.map((voice) => (
                                            <option
                                                key={voice.name}
                                                value={voice.name}
                                            >
                                                {voice.name} ({voice.lang})
                                            </option>
                                        ))}
                                    </select>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        onClick={previewVoice}
                                        aria-label="Preview voice"
                                        title="Preview voice"
                                    >
                                        <Volume2 />
                                    </Button>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Voices come from your browser. Edge&apos;s
                                    “Natural” voices sound the most real.
                                </p>
                                <InputError message={errors.voice_name} />
                            </div>

                            <Button disabled={processing}>Save</Button>
                        </>
                    )}
                </Form>
            </div>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Camera roll"
                    description="Optional. Real photos she can send instead of taking a new selfie. Describe each one so she knows when to send it."
                />

                <Form
                    {...CompanionPhotoController.store.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="space-y-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="photo">Photo</Label>
                                <Input
                                    id="photo"
                                    type="file"
                                    name="photo"
                                    accept="image/*"
                                    required
                                />
                                <InputError message={errors.photo} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="caption">Description</Label>
                                <Input
                                    id="caption"
                                    name="caption"
                                    required
                                    maxLength={200}
                                    placeholder="Mirror selfie in my new red dress"
                                />
                                <InputError message={errors.caption} />
                            </div>
                            <Button disabled={processing}>Add photo</Button>
                        </>
                    )}
                </Form>

                {photos.length > 0 && (
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        {photos.map((photo) => (
                            <figure
                                key={photo.id}
                                className="group relative overflow-hidden rounded-xl border"
                            >
                                <img
                                    src={photo.url}
                                    alt={photo.caption}
                                    className="aspect-square w-full object-cover"
                                />
                                <figcaption className="line-clamp-2 p-2 text-xs text-muted-foreground">
                                    {photo.caption}
                                </figcaption>
                                <Form
                                    {...CompanionPhotoController.destroy.form(
                                        photo.id,
                                    )}
                                    options={{ preserveScroll: true }}
                                    className="absolute top-2 right-2"
                                >
                                    {({ processing }) => (
                                        <Button
                                            size="icon"
                                            variant="destructive"
                                            disabled={processing}
                                            className="size-8 opacity-90"
                                            aria-label="Remove photo"
                                        >
                                            <Trash2 />
                                        </Button>
                                    )}
                                </Form>
                            </figure>
                        ))}
                    </div>
                )}
            </div>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Memories"
                    description="Things she has decided to remember about you"
                />

                {companion.memories.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Nothing yet. Tell her about your life!
                    </p>
                ) : (
                    <>
                        <ul className="list-disc space-y-1 pl-5 text-sm">
                            {companion.memories.map((memory, index) => (
                                <li key={index}>{memory}</li>
                            ))}
                        </ul>
                        <Form
                            {...CompanionController.destroyMemories.form()}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button variant="outline" disabled={processing}>
                                    Forget everything
                                </Button>
                            )}
                        </Form>
                    </>
                )}
            </div>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Start over"
                    description="Delete your whole chat history with her"
                />

                <Dialog
                    open={isClearDialogOpen}
                    onOpenChange={setIsClearDialogOpen}
                >
                    <DialogTrigger asChild>
                        <Button variant="destructive">
                            Clear chat history
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogTitle>Clear your chat history?</DialogTitle>
                        <DialogDescription>
                            Every message, photo, and call log will be deleted.
                            Her profile, camera roll, and memories stay.
                        </DialogDescription>
                        <Form
                            {...MessageController.destroy.form()}
                            onSuccess={() => setIsClearDialogOpen(false)}
                        >
                            {({ processing }) => (
                                <DialogFooter className="gap-2">
                                    <DialogClose asChild>
                                        <Button variant="secondary">
                                            Cancel
                                        </Button>
                                    </DialogClose>
                                    <Button
                                        variant="destructive"
                                        disabled={processing}
                                    >
                                        Clear history
                                    </Button>
                                </DialogFooter>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </>
    );
}

CompanionSettings.layout = {
    breadcrumbs: [
        {
            title: 'Companion settings',
            href: edit(),
        },
    ],
};
