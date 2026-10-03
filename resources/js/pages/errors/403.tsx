import { useTranslation } from '@/hooks/use-translation';
import ErrorPage from './error-page';

export default function Error403() {
    const { t } = useTranslation();

    return (
        <ErrorPage
            status={403}
            title={t('Access denied')}
            message={t("You don't have permission to view this page.")}
        />
    );
}
