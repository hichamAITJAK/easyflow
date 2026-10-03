import { useTranslation } from '@/hooks/use-translation';
import ErrorPage from './error-page';

export default function Error503() {
    const { t } = useTranslation();

    return (
        <ErrorPage
            status={503}
            title={t('Down for maintenance')}
            message={t("We're performing scheduled maintenance. Please check back shortly.")}
        />
    );
}
