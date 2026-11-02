type SpeechRecognitionResultLike = {
    isFinal: boolean;
    0: { transcript: string };
};

type SpeechRecognitionEventLike = {
    resultIndex: number;
    results: ArrayLike<SpeechRecognitionResultLike>;
};

export type SpeechRecognitionLike = {
    lang: string;
    continuous: boolean;
    interimResults: boolean;
    start(): void;
    abort(): void;
    onresult: ((event: SpeechRecognitionEventLike) => void) | null;
    onerror: ((event: { error: string }) => void) | null;
    onend: (() => void) | null;
};

type SpeechRecognitionConstructor = new () => SpeechRecognitionLike;

/**
 * Voice names that tend to sound natural and feminine across browsers and
 * operating systems, best first.
 */
const PREFERRED_VOICES = [
    /aria.*natural/i,
    /jenny.*natural/i,
    /ava.*(natural|premium|enhanced)/i,
    /natural.*female|female.*natural/i,
    /samantha/i,
    /google us english/i,
    /zira/i,
    /female/i,
];

export function supportsVoiceCalls(): boolean {
    return (
        speechRecognitionConstructor() !== null && 'speechSynthesis' in window
    );
}

function speechRecognitionConstructor(): SpeechRecognitionConstructor | null {
    const speechWindow = window as unknown as {
        SpeechRecognition?: SpeechRecognitionConstructor;
        webkitSpeechRecognition?: SpeechRecognitionConstructor;
    };

    return (
        speechWindow.SpeechRecognition ??
        speechWindow.webkitSpeechRecognition ??
        null
    );
}

/**
 * Create a recognizer that listens for a single spoken turn.
 */
export function createSpeechRecognition(): SpeechRecognitionLike | null {
    const Recognition = speechRecognitionConstructor();

    if (!Recognition) {
        return null;
    }

    const recognition = new Recognition();
    recognition.lang = navigator.language || 'en-US';
    recognition.continuous = false;
    recognition.interimResults = true;

    return recognition;
}

/**
 * Resolve the browser's voices, which some browsers load asynchronously.
 */
export function loadVoices(): Promise<SpeechSynthesisVoice[]> {
    if (!('speechSynthesis' in window)) {
        return Promise.resolve([]);
    }

    const voices = window.speechSynthesis.getVoices();

    if (voices.length > 0) {
        return Promise.resolve(voices);
    }

    return new Promise((resolve) => {
        const finish = () => resolve(window.speechSynthesis.getVoices());

        window.speechSynthesis.addEventListener('voiceschanged', finish, {
            once: true,
        });
        window.setTimeout(finish, 1500);
    });
}

export function pickVoice(
    voices: SpeechSynthesisVoice[],
    preferredName: string | null,
): SpeechSynthesisVoice | null {
    const chosen = voices.find((voice) => voice.name === preferredName);

    if (chosen) {
        return chosen;
    }

    const language = (navigator.language || 'en').slice(0, 2);
    const localVoices = voices.filter((voice) =>
        voice.lang.toLowerCase().startsWith(language),
    );

    for (const pattern of PREFERRED_VOICES) {
        const match = localVoices.find((voice) => pattern.test(voice.name));

        if (match) {
            return match;
        }
    }

    return localVoices[0] ?? voices[0] ?? null;
}

/**
 * Strip emojis, *actions*, and formatting so text reads naturally aloud.
 */
export function toSpeakableText(text: string): string {
    return text
        .replace(/\*[^*]+\*/g, '')
        .replace(/\p{Extended_Pictographic}|\u{FE0F}|\u{200D}/gu, '')
        .replace(/\s+/g, ' ')
        .trim();
}

/**
 * Speak one sentence at a time, which avoids Chrome cutting off long
 * utterances. Resolves when finished or when `shouldStop` returns true.
 */
export async function speak(
    text: string,
    voice: SpeechSynthesisVoice | null,
    shouldStop: () => boolean,
): Promise<void> {
    const sentences = toSpeakableText(text).match(/[^.!?]+[.!?]*/g) ?? [];

    for (const sentence of sentences) {
        if (shouldStop()) {
            return;
        }

        await speakSentence(sentence.trim(), voice);
    }
}

function speakSentence(
    sentence: string,
    voice: SpeechSynthesisVoice | null,
): Promise<void> {
    return new Promise((resolve) => {
        if (sentence === '') {
            resolve();

            return;
        }

        const utterance = new SpeechSynthesisUtterance(sentence);
        utterance.rate = 1.02;
        utterance.pitch = 1.05;

        if (voice) {
            utterance.voice = voice;
            utterance.lang = voice.lang;
        }

        // Some browsers never fire `end`, so don't wait forever.
        const timeout = window.setTimeout(
            resolve,
            2000 + sentence.length * 120,
        );
        const finish = () => {
            window.clearTimeout(timeout);
            resolve();
        };

        utterance.onend = finish;
        utterance.onerror = finish;
        window.speechSynthesis.speak(utterance);
    });
}

/**
 * Play a phone ringtone until the returned function is called.
 */
export function startRingtone(): () => void {
    const audio = new AudioContext();

    const ring = () => {
        for (const offset of [0, 0.45]) {
            const start = audio.currentTime + offset;
            const gain = audio.createGain();
            gain.gain.setValueAtTime(0.0001, start);
            gain.gain.exponentialRampToValueAtTime(0.08, start + 0.03);
            gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.38);
            gain.connect(audio.destination);

            for (const frequency of [440, 480]) {
                const oscillator = audio.createOscillator();
                oscillator.frequency.value = frequency;
                oscillator.connect(gain);
                oscillator.start(start);
                oscillator.stop(start + 0.4);
            }
        }
    };

    ring();
    const interval = window.setInterval(ring, 2600);

    return () => {
        window.clearInterval(interval);
        void audio.close();
    };
}
