import useNavigating from '@/hooks/useNavigating';

/** Dims its content and sets aria-busy while a search or filter request for this page is running. */
export default function BusyRegion({ className = '', children }) {
    const busy = useNavigating();

    return (
        <div aria-busy={busy} className={`${className} transition-opacity ${busy ? 'opacity-60' : ''}`}>
            {children}
        </div>
    );
}
