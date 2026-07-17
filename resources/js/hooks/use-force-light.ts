import { useEffect } from 'react';

/**
 * Forces light appearance while the component is mounted, restoring the
 * user's dark preference on unmount. Marketing and auth pages are
 * paper-and-ink light by design regardless of the app theme.
 */
export function useForceLight() {
    useEffect(() => {
        const root = document.documentElement;
        const wasDark = root.classList.contains('dark');
        root.classList.remove('dark');

        return () => {
            if (wasDark) {
                root.classList.add('dark');
            }
        };
    }, []);
}
