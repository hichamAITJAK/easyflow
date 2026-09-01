import ErrorPage from './error-page';

export default function Error503() {
    return (
        <ErrorPage
            status={503}
            title="Down for maintenance"
            message="We're performing scheduled maintenance. Please check back shortly."
        />
    );
}
