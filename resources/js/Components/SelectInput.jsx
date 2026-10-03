export default function SelectInput({ className = '', children, ...props }) {
    return (
        <select
            {...props}
            className={
                'rounded-md border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 ' +
                className
            }
        >
            {children}
        </select>
    );
}
