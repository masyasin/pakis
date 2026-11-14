import { usePage } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { UserMenuContent } from '@/components/user-menu-content';
import { useInitials } from '@/hooks/use-initials';

/**
 * Menu profil di pojok kanan atas header. Desktop: avatar + nama; HP: avatar saja
 * agar header tidak sesak. Isi menu sama dengan UserMenuContent.
 */
export function HeaderUserMenu() {
    const { auth } = usePage().props;
    const getInitials = useInitials();

    if (!auth.user) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    aria-label="Menu profil"
                    data-test="user-menu-button"
                    className="group flex h-10 items-center gap-2 rounded-full p-1 transition-colors duration-150 ease-out outline-none hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring data-[state=open]:bg-accent md:pr-2.5"
                >
                    <Avatar className="size-8 overflow-hidden rounded-full ring-1 ring-border">
                        <AvatarImage
                            src={auth.user.avatar ?? undefined}
                            className="object-cover"
                            alt={auth.user.name}
                        />
                        <AvatarFallback className="rounded-full bg-sidebar-accent text-xs font-semibold text-sidebar-accent-foreground">
                            {getInitials(auth.user.name)}
                        </AvatarFallback>
                    </Avatar>
                    <span className="hidden max-w-40 truncate text-sm font-medium md:inline">
                        {auth.user.name}
                    </span>
                    <ChevronDown
                        aria-hidden="true"
                        className="hidden size-4 text-muted-foreground transition-transform duration-150 group-data-[state=open]:rotate-180 md:block"
                    />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent className="w-64" align="end" sideOffset={8}>
                <UserMenuContent user={auth.user} />
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
