import { t } from '@/lib/i18n';

export default function ApplicationLogo({ alt = t('Škoda Configurator'), ...props }) {
    return <img src="/images/logo.png" alt={alt} {...props} />;
}
