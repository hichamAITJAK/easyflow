import ErrorPage from './error-page';

export default function Error429() {
    return (
        <ErrorPage
            status={429}
            title="Too many requests"
            message="You've made too many requests. Please wait a moment and try again."
        />
    );
}
