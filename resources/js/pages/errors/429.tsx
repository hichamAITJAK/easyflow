import { useTranslation } from '@/hooks/use-translation';
import ErrorPage from './error-page';

export default function Error429() {
    const { t } = useTranslation();

    return (
        <ErrorPage
            status={429}
            title={t('Too many requests')}
            message={t("You've made too many requests. Please wait a moment and try again.")}
        />
    );
}
