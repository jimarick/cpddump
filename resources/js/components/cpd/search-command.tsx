import { router } from '@inertiajs/react';
import { Loader2, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { getJson } from '@/lib/api';

interface SearchResults {
    activities: {
        id: number;
        title: string;
        date: string | null;
        points: number;
        type: string;
        color: string;
    }[];
    inbox: { id: number; title: string; status: string; source: string }[];
}

/** Cmd-K search across activities and the inbox. */
export function SearchCommand() {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<SearchResults>({
        activities: [],
        inbox: [],
    });
    const [busy, setBusy] = useState(false);
    const timer = useRef<ReturnType<typeof setTimeout>>(null);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                e.preventDefault();
                setOpen((o) => !o);
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    useEffect(() => {
        if (timer.current) {
            clearTimeout(timer.current);
        }

        timer.current = setTimeout(async () => {
            if (query.trim().length < 2) {
                setResults({ activities: [], inbox: [] });
                setBusy(false);

                return;
            }

            setBusy(true);

            try {
                setResults(
                    await getJson<SearchResults>(
                        `/search?q=${encodeURIComponent(query)}`,
                    ),
                );
            } catch {
                setResults({ activities: [], inbox: [] });
            } finally {
                setBusy(false);
            }
        }, 250);
    }, [query]);

    const go = (url: string) => {
        setOpen(false);
        setQuery('');
        router.get(url);
    };

    const empty =
        query.trim().length >= 2 &&
        !busy &&
        results.activities.length === 0 &&
        results.inbox.length === 0;

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="flex h-9 cursor-pointer items-center gap-2 rounded-[9px] border-[1.5px] border-ink/20 bg-white px-3 text-[12.5px] whitespace-nowrap text-stone-400 transition-colors hover:border-ink/40 md:w-44"
                title="Search (⌘K)"
            >
                <Search className="size-3.5 shrink-0" />
                <span className="hidden md:inline">Search…</span>
                <kbd className="ml-auto hidden rounded border border-stone-200 bg-paper px-1 text-[10px] text-stone-400 md:inline">
                    ⌘K
                </kbd>
            </button>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="top-[20%] max-w-xl translate-y-0 gap-0 p-0 [&>button]:hidden">
                    <DialogTitle className="sr-only">Search</DialogTitle>
                    <div className="flex items-center gap-2 border-b border-ink/10 px-4">
                        {busy ? (
                            <Loader2 className="size-4 animate-spin text-stone-400" />
                        ) : (
                            <Search className="size-4 text-stone-400" />
                        )}
                        <input
                            autoFocus
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="Search activities, reflections, inbox…"
                            className="w-full bg-transparent py-3.5 text-sm focus:outline-none"
                        />
                    </div>

                    <div className="max-h-[50vh] overflow-y-auto p-2">
                        {results.activities.length > 0 && (
                            <>
                                <div className="px-2 pt-1 pb-1.5 text-[10.5px] font-bold tracking-[0.08em] text-stone-400 uppercase">
                                    Activities
                                </div>
                                {results.activities.map((a) => (
                                    <button
                                        key={`a${a.id}`}
                                        type="button"
                                        onClick={() => go('/timeline')}
                                        className="flex w-full cursor-pointer items-center gap-2.5 rounded-lg px-2 py-2 text-left hover:bg-paper-alt"
                                    >
                                        <span
                                            className="size-2.5 shrink-0 rounded-full"
                                            style={{ backgroundColor: a.color }}
                                        />
                                        <span className="min-w-0 flex-1 truncate text-[13px] font-semibold">
                                            {a.title}
                                        </span>
                                        <span className="text-[11px] whitespace-nowrap text-stone-400">
                                            {a.type} · {a.points} pts
                                        </span>
                                    </button>
                                ))}
                            </>
                        )}

                        {results.inbox.length > 0 && (
                            <>
                                <div className="px-2 pt-2 pb-1.5 text-[10.5px] font-bold tracking-[0.08em] text-stone-400 uppercase">
                                    Inbox
                                </div>
                                {results.inbox.map((i) => (
                                    <button
                                        key={`i${i.id}`}
                                        type="button"
                                        onClick={() => go('/inbox')}
                                        className="flex w-full cursor-pointer items-center gap-2.5 rounded-lg px-2 py-2 text-left hover:bg-paper-alt"
                                    >
                                        <span className="w-14 shrink-0 text-[9.5px] font-bold tracking-[0.08em] text-stone-500 uppercase">
                                            {i.source}
                                        </span>
                                        <span className="min-w-0 flex-1 truncate text-[13px] font-semibold">
                                            {i.title}
                                        </span>
                                        <span className="text-[11px] text-stone-400">
                                            {i.status}
                                        </span>
                                    </button>
                                ))}
                            </>
                        )}

                        {empty && (
                            <div className="px-3 py-6 text-center text-sm text-stone-400">
                                Nothing matched “{query}”.
                            </div>
                        )}
                        {query.trim().length < 2 && (
                            <div className="px-3 py-6 text-center text-sm text-stone-400">
                                Type to search everything you've ever dumped.
                            </div>
                        )}
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}
