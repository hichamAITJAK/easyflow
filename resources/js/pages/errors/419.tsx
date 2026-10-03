import { useTranslation } from '@/hooks/use-translation';
import ErrorPage from './error-page';

export default function Error419() {
    const { t } = useTranslation();

    return (
        <ErrorPage
            status={419}
            title={t('Session expired')}
            message={t('Your session has expired. Please refresh the page and try again.')}
        />
    );
}
